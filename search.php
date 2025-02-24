<?php
include 'db.php'; // Include the database connection

// Function to sanitize and normalize input
function normalize_input($input) {
    return strtolower(str_replace(' ', '', trim($input)));
}

// Function to handle bag-of-words search with normalization
function addNormalizedCondition($field, $input, &$query, &$params, &$types) {
    $normalized = normalize_input($input);
    // Split the input into words (optional, depending on needs)
    $words = preg_split('/\s+/', $input);
    foreach ($words as $word) {
        $word = normalize_input($word);
        if ($word !== '') {
            // Using LIKE for partial matches on normalized field
            $query .= " AND LOWER(REPLACE($field, ' ', '')) LIKE ?";
            $params[] = "%" . $word . "%";
            $types .= 's';
        }
    }
}

// Initialize the base query with joins to pull related attributes
$baseQuery = "
    SELECT 
        Seminars.seminar_id, 
        Seminars.title, 
        Seminars.abstract, 
        Seminars.time_start, 
        Seminars.time_end, 
        Seminars.place, 
        Seminars.image, 
        Seminars.is_visible,
        GROUP_CONCAT(DISTINCT SeminarTypes.name) AS types,
        GROUP_CONCAT(DISTINCT SeminarTags.name) AS tags,
        GROUP_CONCAT(DISTINCT SeminarDisciplines.name) AS disciplines
    FROM Seminars
    LEFT JOIN SeminarTypeMapping ON Seminars.seminar_id = SeminarTypeMapping.seminar_id
    LEFT JOIN SeminarTypes ON SeminarTypeMapping.type_id = SeminarTypes.id
    LEFT JOIN SeminarTagsMapping ON Seminars.seminar_id = SeminarTagsMapping.seminar_id
    LEFT JOIN SeminarTags ON SeminarTagsMapping.tag_id = SeminarTags.id
    LEFT JOIN SeminarDisciplinesMapping ON Seminars.seminar_id = SeminarDisciplinesMapping.seminar_id
    LEFT JOIN SeminarDisciplines ON SeminarDisciplinesMapping.discipline_id = SeminarDisciplines.id
    WHERE 1=1
";

// Initialize parameters and types for the prepared statement
$params = [];
$types = '';

// Flag to determine if fuzzy filtering is needed after SQL query
$use_fuzzy = false;
$fuzzy_field = 'place'; // Field to apply fuzzy matching
$fuzzy_limit = 2;

// Process each GET parameter to build the query dynamically

// Title search
if (!empty($_GET['title'])) {
    addNormalizedCondition('Seminars.title', $_GET['title'], $baseQuery, $params, $types);
}

// Abstract search
if (!empty($_GET['abstract'])) {
    addNormalizedCondition('Seminars.abstract', $_GET['abstract'], $baseQuery, $params, $types);
}

// Place search with normalization and fuzzy matching
if (!empty($_GET['place'])) {
    addNormalizedCondition('Seminars.place', $_GET['place'], $baseQuery, $params, $types);
    $use_fuzzy = true; // Enable fuzzy filtering for 'place'
}

// Date range search
if (!empty($_GET['time_start']) && !empty($_GET['time_end'])) {
    $baseQuery .= " AND Seminars.time_start >= ? AND Seminars.time_end <= ?";
    $params[] = $_GET['time_start'];
    $params[] = $_GET['time_end'];
    $types .= 'ss';
}

// Type search
if (!empty($_GET['type'])) {
    addNormalizedCondition('SeminarTypes.name', $_GET['type'], $baseQuery, $params, $types);
}

// Tag search
if (!empty($_GET['tag'])) {
    addNormalizedCondition('SeminarTags.name', $_GET['tag'], $baseQuery, $params, $types);
}

// Discipline search
if (!empty($_GET['discipline'])) {
    addNormalizedCondition('SeminarDisciplines.name', $_GET['discipline'], $baseQuery, $params, $types);
}

// Image search
if (!empty($_GET['image'])) {
    // Normalize image field
    $normalized_image = normalize_input($_GET['image']);
    $baseQuery .= " AND LOWER(REPLACE(Seminars.image, ' ', '')) LIKE ?";
    $params[] = "%" . $normalized_image . "%";
    $types .= 's';
}

// Group results by seminar_id to handle aggregation
$baseQuery .= " GROUP BY Seminars.seminar_id";

// Prepare and execute the SQL query
$stmt = $conn->prepare($baseQuery);
if ($stmt === false) {
    die("Prepare failed: (" . $conn->errno . ") " . $conn->error);
}

if (!empty($params)) {
    // Dynamically bind parameters
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

// Initialize an array to hold the final filtered results
$filtered_results = [];

// If fuzzy matching is enabled, filter results in PHP
if ($use_fuzzy && !empty($_GET['place'])) {
    $search_place_original = $_GET['place'];
    $search_place_normalized = normalize_input($search_place_original);
    
    while ($row = $result->fetch_assoc()) {
        $place_normalized = normalize_input($row['place'] ?? '');
        $distance = levenshtein($search_place_normalized, $place_normalized);
        if ($distance <= $fuzzy_limit) {
            $filtered_results[] = $row;
        }
    }
} else {
    // No fuzzy filtering needed; include all results
    while ($row = $result->fetch_assoc()) {
        $filtered_results[] = $row;
    }
}

// Close statement and connection
$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Seminar Search</title>
    <link rel="stylesheet" href="css/search.css">
</head>
<body>
    <h1>Search Seminars</h1>
    
    <!-- Search Form -->
    <form action="search.php" method="GET">
        <input type="text" name="title" placeholder="Title" value="<?php echo htmlspecialchars($_GET['title'] ?? ''); ?>">
        <input type="text" name="abstract" placeholder="Abstract" value="<?php echo htmlspecialchars($_GET['abstract'] ?? ''); ?>">
        <input type="datetime-local" name="time_start" placeholder="Start Date" value="<?php echo htmlspecialchars($_GET['time_start'] ?? ''); ?>">
        <input type="datetime-local" name="time_end" placeholder="End Date" value="<?php echo htmlspecialchars($_GET['time_end'] ?? ''); ?>">
        <input type="text" name="place" placeholder="Place" value="<?php echo htmlspecialchars($_GET['place'] ?? ''); ?>">
        <input type="text" name="type" placeholder="Type" value="<?php echo htmlspecialchars($_GET['type'] ?? ''); ?>">
        <input type="text" name="tag" placeholder="Tag" value="<?php echo htmlspecialchars($_GET['tag'] ?? ''); ?>">
        <input type="text" name="discipline" placeholder="Discipline" value="<?php echo htmlspecialchars($_GET['discipline'] ?? ''); ?>">
        <input type="text" name="image" placeholder="Image" value="<?php echo htmlspecialchars($_GET['image'] ?? ''); ?>">
        <br>
        <button type="submit">Search</button>
        <button type="button" onclick="window.location.href='search.php'">Clear Search</button>
    </form>

    <!-- Display Results in Table Format -->
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Title</th>
                <th>Abstract</th>
                <th>Start Time</th>
                <th>End Time</th>
                <th>Place</th>
                <th>Image</th>
                <th>Visible</th>
                <th>Types</th>
                <th>Tags</th>
                <th>Disciplines</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($filtered_results) > 0): ?>
                <?php foreach ($filtered_results as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['seminar_id'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($row['title'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($row['abstract'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($row['time_start'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($row['time_end'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($row['place'] ?? ''); ?></td>
                        <td>
                            <?php if (!empty($row['image'])): ?>
                                <img src="<?php echo htmlspecialchars($row['image']); ?>" alt="Image" width="50">
                            <?php else: ?>
                                No Image
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($row['is_visible'] ? 'Yes' : 'No'); ?></td>
                        <td><?php echo htmlspecialchars($row['types'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($row['tags'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($row['disciplines'] ?? ''); ?></td>
                        <td>
                            <!-- Action Buttons -->
                            <a href="edit_seminar.php?mode=edit&id=<?php echo $row['seminar_id']; ?>" class="action-button edit-button">Edit</a>
                            <a href="delete_seminar.php?id=<?php echo $row['seminar_id']; ?>" class="action-button delete-button" onclick="return confirm('Are you sure you want to delete this seminar?');">Delete</a>
                            <a href="setattendance.php?id=<?php echo $row['seminar_id']; ?>" class="action-button attendance-button">Set Attendance</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="12">No seminars found matching your criteria.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <a href="dashboard.php" id="back-to-dashboard">Back to Dashboard</a>
</body>
</html>
