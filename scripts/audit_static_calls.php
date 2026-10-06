<?php
declare(strict_types=1);

/**
 * Audit sweep B — static calls to methods that don't exist.
 *
 * Collects every app class + its defined methods (and parent links), then
 * walks all project PHP files for Class::method( calls where the class is an
 * app class and the method is defined nowhere in its hierarchy. Catches the
 * "call to undefined method" fatal class of bugs (e.g. the historical
 * Users::email_exists() style drift).
 *
 * usage: php scripts/audit_static_calls.php
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node;

$parser = (new ParserFactory())->createForNewestSupportedVersion();

/** @var array<string, array{methods: array<string,true>, parent: ?string, file: string}> $app */
$app   = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/..', FilesystemIterator::SKIP_DOTS));

$projectFiles = [];
foreach ($files as $f) {
    $p = str_replace('\\', '/', (string) $f->getPathname());
    if (str_contains($p, '/vendor/') || str_contains($p, '/tests/') || !str_ends_with($p, '.php')) {
        continue;
    }
    $projectFiles[] = $p;
}

// --- Pass 1: collect classes, methods, parents -----------------------------
$collector = new class($app) extends NodeVisitorAbstract {
    public function __construct(private array &$app) {}
    public function enterNode(Node $node): void
    {
        if ($node instanceof Node\Stmt\Class_) {
            if ($node->name === null) {
                return; // anonymous class
            }
            $name = $node->name->toString();
            $parent = null;
            if ($node->extends !== null) {
                $parent = $node->extends->toString();
            }
            $methods = [];
            foreach ($node->getProperties() as $prop) { /* noop */ }
            foreach ($node->getMethods() as $m) {
                $methods[strtolower($m->name->toString())] = true;
            }
            $file = str_replace('\\', '/', $node->getStartFilePos() >= 0 ? '' : '');
            $this->app[$name] = [
                'methods' => $methods,
                'parent'  => $parent,
                'file'    => $GLOBALS['current_file'] ?? '',
            ];
        }
    }
};

foreach ($projectFiles as $file) {
    $GLOBALS['current_file'] = $file;
    try {
        $ast = $parser->parse(file_get_contents($file));
    } catch (Throwable $e) {
        echo "!! PARSE ERROR: {$file}: {$e->getMessage()}\n";
        continue;
    }
    $traverser = new NodeTraverser();
    $traverser->addVisitor($collector);
    $traverser->traverse($ast);
}

// Resolve the full method set per class (walk parents; PHP built-ins like PDO
// are outside the project — treat any class we didn't parse as opaque).
$full = [];
$resolve = function (string $class) use (&$resolve, &$full, $app): array {
    if (isset($full[$class])) {
        return $full[$class];
    }
    $full[$class] = $app[$class]['methods'] ?? []; // guard against cycles
    if (isset($app[$class]['parent']) && isset($app[$app[$class]['parent']])) {
        foreach ($resolve($app[$class]['parent']) as $m => $_) {
            $full[$class][$m] = true;
        }
    }
    return $full[$class];
};
foreach (array_keys($app) as $c) {
    $resolve($c);
}

// --- Pass 2: find Class::method( static calls -------------------------------
$checker = new class($app, $full) extends NodeVisitorAbstract {
    public function __construct(private array $app, private array $full) {}
    public function enterNode(Node $node): void
    {
        if (!$node instanceof Node\Expr\StaticCall || !$node->class instanceof Node\Name) {
            return;
        }
        $class = $node->class->toString();
        if (!isset($this->app[$class]) && !isset($this->app[strtolower($class)])) {
            $ci = strtolower($class);
            $hit = null;
            foreach ($this->app as $known => $_) {
                if (strtolower($known) === $ci) { $hit = $known; break; }
            }
            if ($hit === null) {
                return; // not an app class (PDO, PHPUnit, etc.)
            }
            $class = $hit;
        }
        if ($node->name instanceof Node\Expr) {
            return; // dynamic call — can't resolve
        }
        $method = strtolower($node->name->toString());
        if ($method === 'class') {
            return;
        }
        $known = $this->full[$class] ?? [];
        // ::class constants and static constructors of unknown-but-parent-defined
        if (isset($known[$method])) {
            return;
        }
        $file = $GLOBALS['current_file'] ?? '?';
        $line = $node->getStartLine();
        echo "!! {$file}:{$line}: {$class}::{$node->name->toString()}() — NOT DEFINED in {$class} hierarchy\n";
        $GLOBALS['sweep_b_errors'] = ($GLOBALS['sweep_b_errors'] ?? 0) + 1;
    }
};

$GLOBALS['sweep_b_errors'] = 0;
foreach ($projectFiles as $file) {
    $GLOBALS['current_file'] = $file;
    try {
        $ast = $parser->parse(file_get_contents($file));
    } catch (Throwable $e) {
        continue; // already reported
    }
    $traverser = new NodeTraverser();
    $traverser->addVisitor($checker);
    $traverser->traverse($ast);
}

echo $GLOBALS['sweep_b_errors'] === 0
    ? "\nSWEEP B CLEAN\n"
    : "\nSWEEP B: {$GLOBALS['sweep_b_errors']} UNDEFINED STATIC CALL(S)\n";
exit($GLOBALS['sweep_b_errors'] === 0 ? 0 : 1);
