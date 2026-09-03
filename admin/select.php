<?php
declare(strict_types=1);
require_once __DIR__ . '/include/init.php';

if (isset($_POST["id"])) {
    $categoryId = (int) $_POST['id'];

    $output = '
      <div class="table-responsive">
            <table class="table table-bordered">
            <tr>
              <th width="30%"><label>Title</label></th>
              <th width="70%">Description</th>
            </tr>';

    // PDO prepared — fixes SQL injection via $_POST['id']
    $stmt = $db->prepare("SELECT * FROM tbl_features WHERE category_id = :cid");
    $stmt->execute([':cid' => $categoryId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) > 0) {
        foreach ($rows as $row) {
            $output .= '
                        <tr>
                             <td width="30%"><label>' . htmlspecialchars($row["title"]) . '</label></td>
                             <td width="70%">' . htmlspecialchars($row["description"]) . '</td>
                        </tr>';
        }
    } else {
        $output .= '
                        <tr>
                             <td colspan="2" align="center">No Feature Yet!</td>
                        </tr>';
    }
    $output .= '</table></div>';
    echo $output;
}
