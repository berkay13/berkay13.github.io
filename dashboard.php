<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include('db.php');

// Fetch seminars
$seminars = [];
$stmt = $conn->prepare("SELECT 
                            s.seminar_id, 
                            s.title, 
                            s.time_start, 
                            s.time_end, 
                            s.place, 
                            s.image, 
                            s.abstract, 
                            s.is_visible, 
                            GROUP_CONCAT(d.name SEPARATOR ', ') AS disciplines
                        FROM Seminars s
                        LEFT JOIN SeminarDisciplinesMapping sdm ON s.seminar_id = sdm.seminar_id
                        LEFT JOIN SeminarDisciplines d ON sdm.discipline_id = d.id
                        WHERE s.is_visible = 1
                        GROUP BY s.seminar_id
                        ORDER BY s.time_start ASC"); // Order by time
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $seminars[] = $row;
}

// Group seminars
$this_week = [];
$upcoming = [];
$past = [];

$now = new DateTime();
$week_end = clone $now;
$week_end->modify('+7 days');

foreach ($seminars as $seminar) {
    $startDateTime = new DateTime($seminar['time_start']);
    $endDateTime = new DateTime($seminar['time_end'] ?? $seminar['time_start']);

    if ($endDateTime < $now) {
        // Past seminars: End date is earlier than today
        $past[] = $seminar;
    } elseif ($startDateTime <= $week_end) {
        // This Week: Within the next 7 days
        $this_week[] = $seminar;
    } else {
        // Upcoming: After the 7-day period
        $upcoming[] = $seminar;
    }
}
?>


<?php
// Fetch user's email using their SUnet username (stored in session)
$attended_seminars = [];
if (isset($_SESSION['user'])) {
    $sUnet = $_SESSION['user'];
    $user_email = null;

    // Step 1: Retrieve user's email
    $stmt = $conn->prepare("SELECT email FROM Users WHERE SUnet = ?");
    $stmt->bind_param("s", $sUnet);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $user_email = $row['email'];
    }
    $stmt->close();

    // Step 2: Fetch attended seminars if email was successfully retrieved
    if ($user_email) {
        $stmt = $conn->prepare("SELECT 
                                    s.seminar_id, 
                                    s.title, 
                                    s.time_start, 
                                    s.time_end, 
                                    s.place, 
                                    s.image, 
                                    s.abstract, 
                                    GROUP_CONCAT(d.name SEPARATOR ', ') AS disciplines
                                FROM Seminars s
                                LEFT JOIN SeminarDisciplinesMapping sdm ON s.seminar_id = sdm.seminar_id
                                LEFT JOIN SeminarDisciplines d ON sdm.discipline_id = d.id
                                INNER JOIN SeminarAttendance sa ON s.seminar_id = sa.seminar_id
                                WHERE sa.student_email = ?
                                GROUP BY s.seminar_id
                                ORDER BY s.time_start DESC"); // Most recent first
        $stmt->bind_param("s", $user_email);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $attended_seminars[] = $row;
        }
        $stmt->close();
    }
}
$conn->close();
?>

<?php
function renderSeminarSection($header, $seminars, $isAdmin = false) {
    if (empty($seminars)) {
        echo "<h2>" . htmlspecialchars($header) . "</h2><p>No seminars available.</p>";
        return;
    }
    echo "<h2>" . htmlspecialchars($header) . "</h2>";
    echo "<div class='seminars-container'>";
    foreach ($seminars as $seminar) {
        // Format the start and end dates
		if (!empty($seminar['time_start'])) {
			$startDateTime = new DateTime($seminar['time_start']);
		} else {
			$startDateTime = null;
		}
		if (!empty($seminar['time_end'])) {
			$endDateTime = new DateTime($seminar['time_end']);
		} else if ($startDateTime) {
			// Use a default duration if start time exists but end time is missing
			$endDateTime = (clone $startDateTime)->modify('+1 hour');
		} else {
			$endDateTime = null;
		}

		// Determine if it's a past seminar. Use $endDateTime if set, else use current time.
		$isPast = ($endDateTime && $endDateTime < new DateTime());

		// Build Google Calendar URL only if both start and end times exist.
		if ($startDateTime && $endDateTime) {
			$googleCalendarUrl = "https://www.google.com/calendar/render?action=TEMPLATE" .
								 "&text=" . urlencode($seminar['title']) .
								 "&dates=" . $startDateTime->format('Ymd\THis\Z') . "/" . $endDateTime->format('Ymd\THis\Z') .
								 "&details=" . urlencode($seminar['abstract']) .
								 "&location=" . urlencode($seminar['place'] ?? 'TBA') .
								 "&sf=true&output=xml";
		} else {
			$googleCalendarUrl = null;
		}

        // Add a specific class for past seminars
        $cardClass = $isPast ? "seminar-card past-seminar" : "seminar-card";

        ?>
        <div class="<?php echo $cardClass; ?>">
            <?php if (!empty($seminar['image'])): ?>
                <a href="viewdetails.php?id=<?php echo urlencode($seminar['seminar_id']); ?>">
                    <img src="<?php echo htmlspecialchars($seminar['image']); ?>" alt="Seminar Image" class="seminar-image">
                </a>
            <?php endif; ?>
            <div class="seminar-title">
                <a href="viewdetails.php?id=<?php echo urlencode($seminar['seminar_id']); ?>">
                    <?php echo htmlspecialchars($seminar['title']); ?>
                </a>
            </div>
            <div class="seminar-abstract"><?php echo htmlspecialchars($seminar['abstract']); ?></div>
            <div class="seminar-info">
                <strong>Start:</strong> <?php echo empty($seminar['time_start']) ? "TBA" : $startDateTime->format('M. j, l H:i'); ?><br>
                <strong>Place:</strong> <?php echo empty($seminar['place']) ? "TBA" : htmlspecialchars($seminar['place']); ?>
            </div>
            <div class="seminar-disciplines"><?php echo htmlspecialchars($seminar['disciplines'] ?? ''); ?></div>
            <div class="seminar-actions">
                <!-- View Details Button -->
                <a href="viewdetails.php?id=<?php echo urlencode($seminar['seminar_id']); ?>" class="view-details-button">View Details</a>
                
                <?php if (!$isPast && $googleCalendarUrl): ?>
					<a href="<?php echo htmlspecialchars($googleCalendarUrl); ?>" target="_blank" class="add-to-calendar-button">Add to Calendar</a>
				<?php endif; ?>
                
                <?php if ($isAdmin): ?>
                    <a href="edit_seminar.php?mode=edit&id=<?php echo urlencode($seminar['seminar_id']); ?>">Edit</a>
                    <a href="delete_seminar.php?id=<?php echo urlencode($seminar['seminar_id']); ?>" onclick="return confirm('Are you sure you want to delete this seminar?');">Delete</a>
                    <a href="setattendance.php?id=<?php echo urlencode($seminar['seminar_id']); ?>" class="set-attendance-button">Set Attendance</a>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    echo "</div>";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <link rel="stylesheet" href="css/dashboard.css">
</head>
<body>
	
	<a href="dashboard.php"><img src="SUlogo.png" alt="SU Logo" class="logo"></a>

    <h1 class="welcome-text"><?php if (isset($_SESSION['user'])) echo "Welcome, " . htmlspecialchars($_SESSION['user']) . "!"; ?></h1>
	
	<?php if (isset($_SESSION['user'])): ?>
        <a href="logout.php" class="logout-button">Log Out</a>
    <?php else: ?>
        <a href="login.php" class="login-button">Log In</a>
    <?php endif; ?>
	
	<?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']): ?>
    <div class="admin-buttons">
		<button onclick="window.location.href='edit_seminar.php?mode=add'" class="admin-button">Add New Seminar</button>
        <button onclick="window.location.href='search.php'" class="admin-button">Go to Search</button>
        <?php if (isset($_SESSION['is_superadmin']) && $_SESSION['is_superadmin']): ?>
            <button onclick="window.location.href='superadmin.php'" class="admin-button">Go to Superadmin Panel</button>
        <?php endif; ?> 
		<button onclick="window.location.href='manage_tags.php'" class="admin-button">Manage Tags</button>
    </div>
	<?php endif; ?>

    <?php
    renderSeminarSection('This Week', $this_week, isset($_SESSION['is_admin']) && $_SESSION['is_admin']);
    renderSeminarSection('Upcoming', $upcoming, isset($_SESSION['is_admin']) && $_SESSION['is_admin']);
    renderSeminarSection('Past Seminars', $past, isset($_SESSION['is_admin']) && $_SESSION['is_admin']);
	if (isset($_SESSION['user'])) {
		renderSeminarSection("Seminars You've Attended", $attended_seminars);
	}
    ?>
	
	<div class="info-container">
        <span class="info-link" onclick="togglePopup()">Information for graduate students who registered for the graduate seminar course</span>
        <div id="popup" class="popup-window">
            <div class="popup-content">
                <span class="close-btn" onclick="togglePopup()">&times;</span>
                <h3>Information for graduate students who registered for the graduate seminar course:</h3>
                <ul>
                    <li>You can attend <b>one</b> of the seminars each week to fulfill your graduate seminar requirements.</li>
                    <li>For the entire semester, <b>you are required to attend at least 8 seminars.</b></li>
                    <li>Maximum two <b>asynchronous</b> seminar attendances will be counted for each week.</li>
                    <li>Seminars will be on Zoom, hybrid, or physical only. Attendance will be monitored through Zoom reports and/or in person.</li>
                    <li>It is a great opportunity for you to meet renowned scientists and explore new topics. Therefore, we expect your active participation and encourage you to ask questions.</li>
                    <li>For the seminars on Zoom, you must enter using your Sabanci University account, otherwise we won’t be able to count it for attendance.</li>
                    <li>You can ask your questions to our TA Mervenaz Şahin via <a href="mailto:mervenaz.sahin@sabanciniv.edu">mervenaz.sahin@sabanciniv.edu</a>.</li>
                </ul>
            </div>
        </div>
    </div>

    <script>
        function togglePopup() {
            const popup = document.getElementById("popup");
            popup.classList.toggle("show");
        }
    </script>
<div class="background-shape"></div>

	
</body>
</html>
