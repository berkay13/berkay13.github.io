<?php
session_start();
include('db.php');

// Function to check if the user is an admin
function isAdmin() {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'];
}

// Function to sanitize output
function sanitize($data) {
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

// Validate 'id' parameter
if (!isset($_GET['id']) || !filter_var($_GET['id'], FILTER_VALIDATE_INT)) {
    header("Location: dashboard.php?error=Invalid seminar ID.");
    exit();
}

$seminar_id = $_GET['id'];

// Fetch seminar details
$stmt = $conn->prepare("
    SELECT 
        s.*, 
        GROUP_CONCAT(d.name SEPARATOR ', ') AS disciplines
    FROM Seminars s
    LEFT JOIN SeminarDisciplinesMapping sdm ON s.seminar_id = sdm.seminar_id
    LEFT JOIN SeminarDisciplines d ON sdm.discipline_id = d.id
    WHERE s.seminar_id = ? AND s.is_visible = 1
    GROUP BY s.seminar_id
");
$stmt->bind_param("i", $seminar_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo "<!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <title>Seminar Not Found</title>
        <link rel='stylesheet' href='css/viewdetails.css'>
    </head>
    <body>
        <div class='container'>
            <h1>Seminar Not Found</h1>
            <p>The seminar you are looking for does not exist or is not available.</p>
            <a href='dashboard.php' class='back-button'>Back to Dashboard</a>
        </div>
    </body>
    </html>";
    exit();
}

$seminar = $result->fetch_assoc();
$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo sanitize($seminar['title']); ?> - Seminar Details</title>
    <link rel="stylesheet" href="css/viewdetails.css">
    <style>
        /* Embedded JavaScript for Read More/Read Less */
        .toggle-button {
            display: block;
            margin-top: 15px;
            background-color: transparent;
            border: none;
            color: #17a2b8;
            cursor: pointer;
            font-size: 1em;
            text-decoration: underline;
            transition: color 0.3s;
        }

        .toggle-button:hover {
            color: #117a8b;
        }

        .arrow {
            margin-left: 5px;
            transition: transform 0.3s;
        }

        .seminar-abstract.expanded + .toggle-button .arrow {
            transform: rotate(180deg);
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="dashboard.php" class="back-button">&larr; Back to Dashboard</a>
        <div class="seminar-details">
            <!-- Seminar Image -->
            <?php if (!empty($seminar['image'])): ?>
                <div class="seminar-image-container">
                    <img src="<?php echo sanitize($seminar['image']); ?>" alt="Seminar Image" class="seminar-image">
                </div>
            <?php endif; ?>

            <!-- Seminar Information -->
            <div class="seminar-info">
                <h1 class="seminar-title"><?php echo sanitize($seminar['title']); ?></h1>

                <!-- Important Information: Time, Place/Online URL -->
                <div class="seminar-important-info">
                    <?php if (!empty($seminar['time_start'])): ?>
                        <p><strong>Time:</strong> 
                            <?php 
                                $start = new DateTime($seminar['time_start']);
                                $end = !empty($seminar['time_end']) ? new DateTime($seminar['time_end']) : null;
                                echo $start->format('M. j, Y, l H:i') . ($end ? " - " . $end->format('H:i') : '');
                            ?>
                        </p>
                    <?php endif; ?>

                    <?php 
                        if ($seminar['is_physical'] && !empty($seminar['place'])):
                    ?>
                        <p><strong>Place:</strong> <?php echo sanitize($seminar['place']); ?></p>
                    <?php endif; ?>

                    <?php 
                        if ($seminar['is_online'] && !empty($seminar['online_url'])):
                    ?>
                        <p><strong>Online URL:</strong> <?php echo sanitize($seminar['online_url']); ?></p>
                    <?php endif; ?>
                </div>

                <!-- Abstract with Toggle -->
                <?php if (!empty($seminar['abstract'])): ?>
                    <div class="seminar-abstract-container">
                        <h2>Abstract</h2>
                        <p class="seminar-abstract" id="abstract"><?php echo nl2br(sanitize($seminar['abstract'])); ?></p>
                        <button class="toggle-button" id="toggleAbstract" aria-expanded="false" aria-controls="abstract">
                            Read More <span class="arrow">▼</span>
                        </button>
                    </div>
                <?php endif; ?>

                <div class="seminar-meta">
                    <?php if (!empty($seminar['speaker'])): ?>
                        <p><strong>Speaker:</strong> <?php echo sanitize($seminar['speaker']); ?></p>
                    <?php endif; ?>

                    <?php if (!empty($seminar['speaker_bio'])): ?>
                        <p><strong>Speaker Bio:</strong> <?php echo nl2br(sanitize($seminar['speaker_bio'])); ?></p>
                    <?php endif; ?>

                    <?php if (!empty($seminar['disciplines'])): ?>
                        <p><strong>Disciplines:</strong> <?php echo sanitize($seminar['disciplines']); ?></p>
                    <?php endif; ?>
                </div>

                <!-- Admin Information -->
                <?php if (isAdmin()): ?>
                    <div class="admin-details">
                        <h2>Admin Information</h2>
                        <p><strong>Created By:</strong> <?php echo sanitize($seminar['created_by']); ?></p>
                        <p><strong>Created At:</strong> <?php echo (new DateTime($seminar['created_at']))->format('M. j, Y, H:i'); ?></p>
                        <?php if (!empty($seminar['deleted_at'])): ?>
                            <p><strong>Deleted By:</strong> <?php echo sanitize($seminar['deleted_by']); ?></p>
                            <p><strong>Deleted At:</strong> <?php echo (new DateTime($seminar['deleted_at']))->format('M. j, Y, H:i'); ?></p>
                        <?php endif; ?>
                        <p><strong>Last Reminder Sent:</strong> <?php 
                            echo !empty($seminar['last_reminder_sent']) ? (new DateTime($seminar['last_reminder_sent']))->format('M. j, Y, H:i') : 'Never';
                        ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!empty($seminar['abstract'])): ?>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const toggleButton = document.getElementById('toggleAbstract');
            const abstract = document.getElementById('abstract');

            // Function to check if the abstract overflows
            function isOverflowing(element) {
                return element.scrollHeight > element.clientHeight;
            }

            // Initially check if abstract is overflowing
            if (!isOverflowing(abstract)) {
                toggleButton.style.display = 'none';
            }

            // Toggle functionality
            toggleButton.addEventListener('click', () => {
                abstract.classList.toggle('expanded');
                const expanded = abstract.classList.contains('expanded');
                toggleButton.textContent = expanded ? 'Read Less ▼' : 'Read More ▼';
                toggleButton.setAttribute('aria-expanded', expanded);
            });
        });
    </script>
    <?php endif; ?>
</body>
</html>
