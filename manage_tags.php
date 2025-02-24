<?php
session_start();
include('db.php');

// Check if the user is an admin or superadmin
if (!isset($_SESSION['user']) || (!$_SESSION['is_admin'] && !$_SESSION['is_superadmin'])) {
    header("Location: login.php");
    exit();
}

// Handle POST actions: Add, Edit, Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];
    $type = $_POST['type'];

    if ($action === 'add' && !empty($_POST['name'])) {
        $name = $_POST['name'];
        $table = ($type === 'discipline') ? 'SeminarDisciplines' : (($type === 'tag') ? 'SeminarTags' : 'SeminarTypes');
        $stmt = $conn->prepare("INSERT INTO $table (name) VALUES (?)");
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $stmt->close();
    }

    if ($action === 'edit' && !empty($_POST['id']) && !empty($_POST['name'])) {
        $id = $_POST['id'];
        $name = $_POST['name'];
        $table = ($type === 'discipline') ? 'SeminarDisciplines' : (($type === 'tag') ? 'SeminarTags' : 'SeminarTypes');
        $stmt = $conn->prepare("UPDATE $table SET name = ? WHERE id = ?");
        $stmt->bind_param('si', $name, $id);
        $stmt->execute();
        $stmt->close();
    }

    if ($action === 'delete' && !empty($_POST['id'])) {
        $id = $_POST['id'];
        // Determine which table and mapping column to use based on type.
        if ($type === 'discipline') {
            $table = 'SeminarDisciplines';
            $mapping_table = 'SeminarDisciplinesMapping';
            $col_name = 'discipline_id';
        } elseif ($type === 'tag') {
            $table = 'SeminarTags';
            $mapping_table = 'SeminarTagsMapping';
            $col_name = 'tag_id';
        } else { // type === 'type'
            $table = 'SeminarTypes';
            $mapping_table = 'SeminarTypeMapping';
            $col_name = 'type_id';
        }
        
        // Check if the item is in use in any seminar.
        $stmt = $conn->prepare("SELECT s.seminar_id, s.title FROM Seminars s 
                                JOIN $mapping_table m ON s.seminar_id = m.seminar_id 
                                WHERE m.$col_name = ?");
        if (!$stmt) {
            die("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $seminars_in_use = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // If there are seminars using this item and deletion hasn't been confirmed, show warning.
        if (count($seminars_in_use) > 0 && !isset($_POST['confirm_delete'])) {
            echo "<h2>Warning: $type in use</h2>";
            echo "<p>The selected $type is currently applied to the following seminars:</p>";
            echo "<ul>";
            foreach ($seminars_in_use as $seminar) {
                echo "<li>Seminar ID " . $seminar['seminar_id'] . " - " . htmlspecialchars($seminar['title']) . "</li>";
            }
            echo "</ul>";
            echo "<p>Deleting this $type will remove it from the above seminars but will <strong>not delete the seminars</strong> themselves.</p>";
            echo "<p>Are you sure you want to proceed with deletion?</p>";
            echo "<form method='post'>";
            echo "<input type='hidden' name='type' value='" . htmlspecialchars($type) . "'>";
            echo "<input type='hidden' name='action' value='delete'>";
            echo "<input type='hidden' name='id' value='" . htmlspecialchars($id) . "'>";
            echo "<input type='hidden' name='confirm_delete' value='1'>";
            echo "<button type='submit'>Yes, delete anyway</button>";
            echo " <a href='manage_tags.php'>Cancel</a>";
            echo "</form>";
            // Stop further processing.
            exit();
        }
        
        // If deletion is confirmed or no seminars are using this item, first delete any mapping entries.
        $stmt = $conn->prepare("DELETE FROM $mapping_table WHERE $col_name = ?");
        if (!$stmt) {
            die("Prepare failed (mapping deletion): " . $conn->error);
        }
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            die("Mapping deletion failed: " . $stmt->error);
        }
        $stmt->close();
        
        // Now proceed with deleting the item from the parent table.
        $stmt = $conn->prepare("DELETE FROM $table WHERE id = ?");
        if (!$stmt) {
            die("Prepare failed (parent deletion): " . $conn->error);
        }
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            die("Deletion failed: " . $stmt->error);
        }
        $stmt->close();
    }
}

// Fetch all current records
$disciplines = $conn->query("SELECT * FROM SeminarDisciplines")->fetch_all(MYSQLI_ASSOC);
$tags = $conn->query("SELECT * FROM SeminarTags")->fetch_all(MYSQLI_ASSOC);
$types = $conn->query("SELECT * FROM SeminarTypes")->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Tags, Disciplines, and Types</title>
    <link rel="stylesheet" href="css/manage_tags.css">
</head>
<body>
    <h1>Manage Disciplines, Tags, and Types</h1>
    
    <a href="dashboard.php" id="back-to-dashboard">Back to Dashboard</a>

    <?php
    function renderTable($title, $data, $type) {
        echo "<h2>$title</h2>";
        echo "<table>";
        echo "<tr>
                <th onclick='sortTable(this, 0)'>ID</th>
                <th onclick='sortTable(this, 1)'>Name</th>
                <th>Actions</th>
              </tr>";
        foreach ($data as $row) {
            echo "<tr>
                    <td>{$row['id']}</td>
                    <td>" . htmlspecialchars($row['name']) . "</td>
                    <td>
                        <form method='post' style='display:inline;'>
                            <input type='hidden' name='type' value='$type'>
                            <input type='hidden' name='action' value='edit'>
                            <input type='hidden' name='id' value='{$row['id']}'>
                            <input type='text' name='name' placeholder='Rename' required>
                            <button type='submit'>Rename</button>
                        </form>
                        <form method='post' style='display:inline;' onsubmit='return confirmDelete(\"" . htmlspecialchars($row['name']) . "\")'>
                            <input type='hidden' name='type' value='$type'>
                            <input type='hidden' name='action' value='delete'>
                            <input type='hidden' name='id' value='{$row['id']}'>
                            <button type='submit'>Delete</button>
                        </form>
                    </td>
                  </tr>";
        }
        echo "</table>";
        echo "<form method='post' class='add-form'>
                <input type='hidden' name='type' value='$type'>
                <input type='hidden' name='action' value='add'>
                <input type='text' name='name' placeholder='New " . rtrim($title, 's') . "' required>
                <button type='submit'>Add</button>
              </form>";
    }

    renderTable('Disciplines', $disciplines, 'discipline');
    renderTable('Tags', $tags, 'tag');
    renderTable('Types', $types, 'type');
    ?>

    <script>
        function confirmDelete(name) {
            return confirm("Are you sure you want to delete '" + name + "'?");
        }

        function sortTable(header, columnIndex) {
            const table = header.closest('table');
            const rows = Array.from(table.rows).slice(1);
            const ascending = table.dataset.sortOrder !== 'asc';

            rows.sort((rowA, rowB) => {
                const cellA = rowA.cells[columnIndex].innerText.trim();
                const cellB = rowB.cells[columnIndex].innerText.trim();
                return ascending ? cellA.localeCompare(cellB, undefined, {numeric: true}) 
                                 : cellB.localeCompare(cellA, undefined, {numeric: true});
            });

            rows.forEach(row => table.tBodies[0].appendChild(row));
            table.dataset.sortOrder = ascending ? 'asc' : 'desc';
        }
    </script>
</body>
</html>
