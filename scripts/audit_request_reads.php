<?php
declare(strict_types=1);
require __DIR__ . '/cli_guard.php'; // CLI-only: refuse to run over HTTP

/**
 * Audit sweep H — superglobal reads that would warn on a missing field.
 *
 * `$_POST['email']` on a request that did not send `email` is an "Undefined
 * array key" warning on PHP 8.x. display_errors is off on a live install, so
 * the visitor sees nothing while the warning lands in the error log — the
 * defect is invisible until someone reads the log or flips WPMS_DEBUG on. The
 * `Request` helper (admin/include/Request.php) and a `?? ''` at the read are
 * the two accepted ways to make a read safe.
 *
 * This sweep finds the UNSAFE reads with the AST, not with a regex, so it does
 * not cry wolf on the safe shapes:
 *   - `$_POST['x'] ?? ''`              (the coalesce)
 *   - `isset($_POST['x'])`, `empty(...)`, `array_key_exists('x', $_POST)`
 *   - a read inside the THEN branch of `if (isset($_POST['x']))`
 *   - a read in the RIGHT side of `isset($_POST['x']) && $_POST['x'] === 'y'`
 *
 * It is a RATCHET, not a to-do list: `docs/REQUEST_READS_BASELINE.txt` holds
 * the known count. The sweep FAILS when a file grows the count (a new bare
 * read), and prints the delta. Run `--update-baseline` to lower the ratchet
 * after hardening a page — never to raise it without a reason.
 *
 * usage:
 *   php scripts/audit_request_reads.php                 # check against the baseline
 *   php scripts/audit_request_reads.php --list          # show every unsafe read
 *   php scripts/audit_request_reads.php --update-baseline
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpParser\ParserFactory;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\NodeVisitorAbstract;

$root        = dirname(__DIR__);
$baselineFile = $root . '/docs/REQUEST_READS_BASELINE.txt';
$listAll     = in_array('--list', $argv, true);
$updateBase  = in_array('--update-baseline', $argv, true);

$SUPERGLOBALS = ['_POST', '_GET', '_REQUEST'];
$GUARD_FUNCS  = ['isset', 'empty', 'array_key_exists', 'property_exists'];

$parser = (new ParserFactory())->createForNewestSupportedVersion();

/** Skip these subtrees: vendored code, the test tier, one-off tooling. */
$skipDirs = ['/vendor/', '/tests/', '/scripts/', '/DATABASE FILE/', '/dist/', '/node_modules/'];

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$projectFiles = [];
foreach ($files as $f) {
    $p = str_replace('\\', '/', (string) $f->getPathname());
    if (!str_ends_with($p, '.php')) {
        continue;
    }
    foreach ($skipDirs as $skip) {
        if (str_contains($p, $skip)) {
            continue 2;
        }
    }
    $projectFiles[] = $p;
}
sort($projectFiles);

/** Does this expression reference $superglobal['key'] inside a guard call? */
function expr_guards_key(?Node $node, string $superglobal, string $key): bool
{
    if ($node === null) {
        return false;
    }
    $found = false;
    $walk = function (Node $n) use (&$walk, &$found, $superglobal, $key): void {
        if ($found) {
            return;
        }
        // isset()/empty() are language constructs (Isset_/Empty_), not FuncCall.
        // Isset_ holds many `vars`; Empty_ holds a single `expr`.
        if ($n instanceof Node\Expr\Isset_ || $n instanceof Node\Expr\Empty_) {
            $cands = $n instanceof Node\Expr\Isset_ ? $n->vars : [$n->expr];
            foreach ($cands as $v) {
                if ($v instanceof Node\Expr\ArrayDimFetch
                    && $v->var instanceof Node\Expr\Variable
                    && $v->var->name === $superglobal
                    && $v->dim instanceof Node\Scalar\String_
                    && $v->dim->value === $key) {
                    $found = true;
                    return;
                }
            }
        }
        if ($n instanceof Node\Expr\FuncCall && $n->name instanceof Node\Name) {
            $fn = strtolower($n->name->toString());
            if (in_array($fn, ['isset', 'empty', 'array_key_exists', 'property_exists'], true)) {
                foreach ($n->getArgs() as $arg) {
                    $v = $arg->value;
                    // isset($X['key']) / empty($X['key'])
                    if ($v instanceof Node\Expr\ArrayDimFetch
                        && $v->var instanceof Node\Expr\Variable
                        && $v->var->name === $superglobal
                        && $v->dim instanceof Node\Scalar\String_
                        && $v->dim->value === $key) {
                        $found = true;
                        return;
                    }
                    // array_key_exists('key', $X)
                    if ($fn === 'array_key_exists' && $v instanceof Node\Expr\Variable && $v->name === $superglobal) {
                        $args = $n->getArgs();
                        if (isset($args[0]) && $args[0]->value instanceof Node\Scalar\String_
                            && $args[0]->value->value === $key) {
                            $found = true;
                            return;
                        }
                    }
                }
            }
        }
        foreach ($n->getSubNodeNames() as $name) {
            $child = $n->$name;
            if ($child instanceof Node) {
                $walk($child);
            } elseif (is_array($child)) {
                foreach ($child as $c) {
                    if ($c instanceof Node) {
                        $walk($c);
                    }
                }
            }
        }
    };
    $walk($node);
    return $found;
}

final class ReadCollector extends NodeVisitorAbstract
{
    /** @var array<int,array{file:string,line:int,text:string}> */
    public array $unsafe = [];

    public function __construct(
        private string $file,
        private array $superglobals,
    ) {}

    public function enterNode(Node $node): ?int
    {
        if (!$node instanceof Node\Expr\ArrayDimFetch) {
            return null;
        }
        $var = $node->var;
        if (!$var instanceof Node\Expr\Variable || !is_string($var->name) || !in_array($var->name, $this->superglobals, true)) {
            return null;
        }
        $key = null;
        if ($node->dim instanceof Node\Scalar\String_) {
            $key = $node->dim->value;
        } elseif ($node->dim === null) {
            // $X[] — a write target, not a read; ignore.
            return null;
        }
        if ($this->isSafe($node, $var->name, $key)) {
            return null;
        }
        $this->unsafe[] = [
            'file' => $this->file,
            'line' => $node->getStartLine(),
            'text' => '$' . $var->name . "['" . ($key ?? '?') . "']",
        ];
        return null;
    }

    private function isSafe(Node\Expr\ArrayDimFetch $node, string $superglobal, ?string $key): bool
    {
        if ($key === null) {
            return true; // dynamic key: cannot prove unsafe, do not flag
        }
        $current = $node;
        $parent  = $node->getAttribute('parent');
        while ($parent instanceof Node) {
            // (1) the coalesce: `$X['k'] ?? default`
            if ($parent instanceof Node\Expr\BinaryOp\Coalesce && $parent->left === $current) {
                return true;
            }
            // (2) inside a guard: isset($X['k']) / empty($X['k']) (constructs),
            //     or array_key_exists/property_exists (real functions).
            if ($parent instanceof Node\Expr\Isset_) {
                if (in_array($current, $parent->vars, true)) {
                    return true;
                }
            }
            if ($parent instanceof Node\Expr\Empty_ && $parent->expr === $current) {
                return true;
            }
            if ($parent instanceof Node\Arg) {
                $call = $parent->getAttribute('parent');
                if ($call instanceof Node\Expr\FuncCall
                    && $call->name instanceof Node\Name
                    && in_array(strtolower($call->name->toString()), ['array_key_exists', 'property_exists'], true)) {
                    return true;
                }
            }
            // (3) THEN-branch of a guard on the same key
            if ($parent instanceof Node\Stmt\If_ || $parent instanceof Node\Stmt\ElseIf_) {
                $inBody = false;
                foreach ($parent->stmts as $stmt) {
                    if ($current === $stmt || self::containsNode($stmt, $current)) {
                        $inBody = true;
                        break;
                    }
                }
                if ($inBody && expr_guards_key($parent->cond, $superglobal, $key)) {
                    return true;
                }
            }
            // (4) right side of a short-circuit whose left guards the key
            if ($parent instanceof Node\Expr\BinaryOp\BooleanAnd || $parent instanceof Node\Expr\BinaryOp\BooleanOr) {
                if ($current === $parent->right && expr_guards_key($parent->left, $superglobal, $key)) {
                    return true;
                }
            }
            // (5) ternary then-expression guarded by its condition
            if ($parent instanceof Node\Expr\Ternary) {
                if ($parent->if === $current && expr_guards_key($parent->cond, $superglobal, $key)) {
                    return true;
                }
            }
            $current = $parent;
            $parent = $parent->getAttribute('parent');
        }
        return false;
    }

    private static function containsNode(Node $haystack, Node $needle): bool
    {
        if ($haystack === $needle) {
            return true;
        }
        foreach ($haystack->getSubNodeNames() as $name) {
            $child = $haystack->$name;
            if ($child instanceof Node && self::containsNode($child, $needle)) {
                return true;
            }
            if (is_array($child)) {
                foreach ($child as $c) {
                    if ($c instanceof Node && self::containsNode($c, $needle)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }
}

$all = [];
$parseErrors = 0;
foreach ($projectFiles as $file) {
    $code = file_get_contents($file);
    if ($code === false) {
        continue;
    }
    try {
        $ast = $parser->parse($code);
    } catch (Throwable $e) {
        $parseErrors++;
        continue;
    }
    if ($ast === null) {
        continue;
    }
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ParentConnectingVisitor());
    $ast = $traverser->traverse($ast);
    $collector = new ReadCollector(str_replace('\\', '/', $file), $SUPERGLOBALS);
    $t2 = new NodeTraverser();
    $t2->addVisitor($collector);
    $t2->traverse($ast);
    foreach ($collector->unsafe as $hit) {
        $all[] = $hit;
    }
}

$count = count($all);
$baseline = is_file($baselineFile) ? (int) trim((string) file_get_contents($baselineFile)) : null;

echo "=== SWEEP H — superglobal reads that warn on a missing field ===\n";
echo 'files scanned: ' . count($projectFiles) . " | unsafe reads: {$count}\n";

if ($listAll) {
    foreach ($all as $hit) {
        echo '  ' . $hit['file'] . ':' . $hit['line'] . '  ' . $hit['text'] . "\n";
    }
}

if ($updateBase) {
    if (!is_dir(dirname($baselineFile))) {
        mkdir(dirname($baselineFile), 0777, true);
    }
    file_put_contents($baselineFile, $count . "\n");
    echo "baseline updated to {$count}\n";
    exit(0);
}

if ($baseline === null) {
    echo 'no baseline file at docs/REQUEST_READS_BASELINE.txt — run --update-baseline to record ' . $count . "\n";
    exit(0);
}

if ($count > $baseline) {
    echo "SWEEP H FAIL — {$count} unsafe reads, baseline {$baseline} (regression of " . ($count - $baseline) . ")\n";
    if (!$listAll) {
        echo "re-run with --list to see them\n";
    }
    exit(1);
}

echo "SWEEP H CLEAN ({$count} unsafe reads, at or below the baseline of {$baseline}" . ($parseErrors ? ", {$parseErrors} unparseable file(s) skipped" : '') . ")\n";
