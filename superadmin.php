<?php
session_start();
include('db.php');

// Ensure the user is logged in and is a superadmin
if (!isset($_SESSION['user']) || !$_SESSION['is_superadmin']) {
    header("Location: login.php");
    exit();
}

// Handle AJAX requests for updating privileges, deleting users, and adding users
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    // Update Privileges
    if ($_POST['action'] === 'update_privileges') {
        $SUnet = $_POST['SUnet'];
        // Correctly parse '1' or '0' instead of using isset()
        $is_seminar_admin = ($_POST['is_seminar_admin'] === '1') ? 1 : 0;
        $is_superadmin = ($_POST['is_superadmin'] === '1') ? 1 : 0;

        // Prevent self-demotion
        if ($SUnet === $_SESSION['user'] && !$is_superadmin) {
            echo json_encode(['success' => false, 'message' => 'You cannot demote yourself from superadmin.']);
            exit();
        }

        // Update user privileges
        $stmt = $conn->prepare("UPDATE Users SET is_seminar_admin = ?, is_superadmin = ? WHERE SUnet = ?");
        if (!$stmt) {
            echo json_encode(['success' => false, 'message' => "Prepare failed: " . $conn->error]);
            exit();
        }

        $stmt->bind_param('iis', $is_seminar_admin, $is_superadmin, $SUnet);
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => "Execute failed: " . $stmt->error]);
        }
        $stmt->close();
        exit();
    }

    // Delete User
    if ($_POST['action'] === 'delete_user') {
        $SUnet = $_POST['SUnet'];

        // Prevent self-deletion
        if ($SUnet === $_SESSION['user']) {
            echo json_encode(['success' => false, 'message' => 'You cannot delete your own account.']);
            exit();
        }

        // Delete user
        $stmt = $conn->prepare("DELETE FROM Users WHERE SUnet = ?");
        if (!$stmt) {
            echo json_encode(['success' => false, 'message' => "Prepare failed: " . $conn->error]);
            exit();
        }

        $stmt->bind_param('s', $SUnet);
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => "Execute failed: " . $stmt->error]);
        }
        $stmt->close();
        exit();
    }

    // Add User
    if ($_POST['action'] === 'add_user') {
        // Retrieve and sanitize input
        $SUnet = trim($_POST['SUnet']);
        $email = trim($_POST['email']);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        $is_seminar_admin = isset($_POST['is_seminar_admin']) && $_POST['is_seminar_admin'] === '1' ? 1 : 0;
        $is_superadmin = isset($_POST['is_superadmin']) && $_POST['is_superadmin'] === '1' ? 1 : 0;

        // Basic validation
        if (empty($SUnet) || empty($email) || empty($password) || empty($confirm_password)) {
            echo json_encode(['success' => false, 'message' => 'All fields are required.']);
            exit();
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
            exit();
        }

        if ($password !== $confirm_password) {
            echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
            exit();
        }

        // Check if SUnet or email already exists
        $stmt = $conn->prepare("SELECT SUnet, email FROM Users WHERE SUnet = ? OR email = ?");
        if (!$stmt) {
            echo json_encode(['success' => false, 'message' => "Prepare failed: " . $conn->error]);
            exit();
        }

        $stmt->bind_param('ss', $SUnet, $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'SUnet or Email already exists.']);
            $stmt->close();
            exit();
        }
        $stmt->close();

        // Hash the password
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        if ($password_hash === false) {
            echo json_encode(['success' => false, 'message' => 'Password hashing failed.']);
            exit();
        }

        // Insert the new user
        $stmt = $conn->prepare("INSERT INTO Users (SUnet, password_hash, is_superadmin, is_seminar_admin, email) VALUES (?, ?, ?, ?, ?)");
        if (!$stmt) {
            echo json_encode(['success' => false, 'message' => "Prepare failed: " . $conn->error]);
            exit();
        }

        $stmt->bind_param('ssiss', $SUnet, $password_hash, $is_superadmin, $is_seminar_admin, $email);
        if ($stmt->execute()) {
            // Fetch the newly added user to return its data
            $new_user = [
                'SUnet' => htmlspecialchars($SUnet),
                'is_seminar_admin' => $is_seminar_admin,
                'is_superadmin' => $is_superadmin
            ];
            echo json_encode(['success' => true, 'user' => $new_user]);
        } else {
            echo json_encode(['success' => false, 'message' => "Execute failed: " . $stmt->error]);
        }
        $stmt->close();
        exit();
    }
}

// Fetch all users
$stmt = $conn->prepare("SELECT SUnet, is_seminar_admin, is_superadmin FROM Users");
if (!$stmt) {
    die("Prepare failed: " . $conn->error); // Debugging line
}

$stmt->execute();
$result = $stmt->get_result();
$users = [];
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}

$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Superadmin Panel</title>
    <link rel="stylesheet" href="css/superadmin.css">
</head>
<body>
    <a href="dashboard.php" id="back-to-dashboard">Back to Dashboard</a>	
    <h1>Superadmin Panel</h1>

    <!-- Users Table -->
    <table>
        <thead>
            <tr>
                <th>Username (SUnet)</th>
                <th>Admin</th>
                <th>Superadmin</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="users-table-body">
            <?php foreach ($users as $user): ?>
                <tr id="user-<?php echo htmlspecialchars($user['SUnet']); ?>">
                    <td><?php echo htmlspecialchars($user['SUnet']); ?></td>
                    <td>
                        <label class="switch">
                            <input type="checkbox" class="toggle-admin" data-sunet="<?php echo htmlspecialchars($user['SUnet']); ?>" <?php echo $user['is_seminar_admin'] ? 'checked' : ''; ?> value="<?php echo $user['is_seminar_admin'] ? '1' : '0'; ?>">
                            <span class="slider round"></span>
                        </label>
                    </td>
                    <td>
                        <label class="switch">
                            <input type="checkbox" class="toggle-superadmin" data-sunet="<?php echo htmlspecialchars($user['SUnet']); ?>" <?php echo $user['is_superadmin'] ? 'checked' : ''; ?> value="<?php echo $user['is_superadmin'] ? '1' : '0'; ?>">
                            <span class="slider round"></span>
                        </label>
                    </td>
                    <td>
                        <?php if ($user['SUnet'] !== $_SESSION['user']): ?>
                            <button class="delete-button" data-sunet="<?php echo htmlspecialchars($user['SUnet']); ?>">Delete</button>
                        <?php else: ?>
                            <!-- Prevent deleting self -->
                            <button class="delete-button" disabled>Delete</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Add User Button -->
    <div class="add-user-button-container">
        <button id="show-add-user-form" class="add-user-button">Add New User</button>
    </div>

    <!-- Add User Form (Initially Hidden) -->
    <div class="add-user-container" id="add-user-container" style="display: none;">
        <h2>Add New User</h2>
        <form id="add-user-form">
            <div class="form-group">
                <label for="SUnet">SUnet:</label>
                <input type="text" id="SUnet" name="SUnet" required>
            </div>
            <div class="form-group">
                <label for="email">Email:</label>
                <input type="email" id="email" name="email" required>
            </div>
            <div class="form-group">
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" required>
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirm Password:</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
            </div>
            <div class="form-group checkbox-group">
                <label for="is_seminar_admin">Seminar Admin:</label>
                <input type="checkbox" id="is_seminar_admin" name="is_seminar_admin" value="1">
            </div>
            <div class="form-group checkbox-group">
                <label for="is_superadmin">Superadmin:</label>
                <input type="checkbox" id="is_superadmin" name="is_superadmin" value="1">
            </div>
            <button type="submit" class="add-user-button">Add User</button>
            <button type="button" id="hide-add-user-form" class="cancel-button">Cancel</button>
        </form>
        <div id="add-user-message"></div>
    </div>

    <!-- JavaScript for handling AJAX requests and form toggling -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Handle toggle switches
            const toggleAdminSwitches = document.querySelectorAll('.toggle-admin');
            const toggleSuperadminSwitches = document.querySelectorAll('.toggle-superadmin');
            const deleteButtons = document.querySelectorAll('.delete-button');
            const addUserForm = document.getElementById('add-user-form');
            const addUserMessage = document.getElementById('add-user-message');
            const usersTableBody = document.getElementById('users-table-body');
            const showAddUserFormButton = document.getElementById('show-add-user-form');
            const hideAddUserFormButton = document.getElementById('hide-add-user-form');
            const addUserContainer = document.getElementById('add-user-container');

            // Function to show the Add User form
            showAddUserFormButton.addEventListener('click', function() {
                addUserContainer.style.display = 'block';
                showAddUserFormButton.style.display = 'none';
            });

            // Function to hide the Add User form
            hideAddUserFormButton.addEventListener('click', function() {
                addUserContainer.style.display = 'none';
                showAddUserFormButton.style.display = 'block';
                addUserForm.reset();
                addUserMessage.textContent = '';
                addUserMessage.className = '';
            });

            // Update Privileges Function
            function updatePrivileges(SUnet, is_seminar_admin, is_superadmin) {
                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'superadmin.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onreadystatechange = function() {
                    if (xhr.readyState === 4) {
                        if (xhr.status === 200) {
                            try {
                                const response = JSON.parse(xhr.responseText);
                                if (!response.success) {
                                    alert('Error: ' + response.message);
                                    // Revert the toggle switches
                                    const adminSwitch = document.querySelector(`.toggle-admin[data-sunet="${SUnet}"]`);
                                    const superadminSwitch = document.querySelector(`.toggle-superadmin[data-sunet="${SUnet}"]`);
                                    adminSwitch.checked = is_seminar_admin === '1';
                                    superadminSwitch.checked = is_superadmin === '1';
                                }
                            } catch (e) {
                                alert('Invalid server response.');
                            }
                        } else {
                            alert('An error occurred while updating privileges.');
                        }
                    }
                };
                const params = `action=update_privileges&SUnet=${encodeURIComponent(SUnet)}&is_seminar_admin=${is_seminar_admin}&is_superadmin=${is_superadmin}`;
                xhr.send(params);
            }

            // Delete User Function
            function deleteUser(SUnet) {
                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'superadmin.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onreadystatechange = function() {
                    if (xhr.readyState === 4) {
                        if (xhr.status === 200) {
                            try {
                                const response = JSON.parse(xhr.responseText);
                                if (response.success) {
                                    // Remove the user's row from the table
                                    const userRow = document.getElementById(`user-${SUnet}`);
                                    if (userRow) {
                                        userRow.remove();
                                    }
                                } else {
                                    alert('Error: ' + response.message);
                                }
                            } catch (e) {
                                alert('Invalid server response.');
                            }
                        } else {
                            alert('An error occurred while deleting the user.');
                        }
                    }
                };
                const params = `action=delete_user&SUnet=${encodeURIComponent(SUnet)}`;
                xhr.send(params);
            }

            // Handle toggle admin switches
            toggleAdminSwitches.forEach(function(switchElem) {
                switchElem.addEventListener('change', function() {
                    const SUnet = this.getAttribute('data-sunet');
                    const is_seminar_admin = this.checked ? '1' : '0';
                    const superadminSwitch = document.querySelector(`.toggle-superadmin[data-sunet="${SUnet}"]`);
                    const is_superadmin = superadminSwitch.checked ? '1' : '0';

                    updatePrivileges(SUnet, is_seminar_admin, is_superadmin);
                });
            });

            // Handle toggle superadmin switches
            toggleSuperadminSwitches.forEach(function(switchElem) {
                switchElem.addEventListener('change', function() {
                    const SUnet = this.getAttribute('data-sunet');
                    const is_superadmin = this.checked ? '1' : '0';
                    const adminSwitch = document.querySelector(`.toggle-admin[data-sunet="${SUnet}"]`);
                    const is_seminar_admin = adminSwitch.checked ? '1' : '0';

                    updatePrivileges(SUnet, is_seminar_admin, is_superadmin);
                });
            });

            // Handle delete buttons
            deleteButtons.forEach(function(button) {
                button.addEventListener('click', function() {
                    const SUnet = this.getAttribute('data-sunet');
                    if (confirm(`Are you sure you want to delete user ${SUnet}?`)) {
                        deleteUser(SUnet);
                    }
                });
            });

            // Handle Add User Form Submission
            addUserForm.addEventListener('submit', function(event) {
                event.preventDefault(); // Prevent default form submission

                // Gather form data
                const formData = new FormData(addUserForm);
                const SUnet = formData.get('SUnet').trim();
                const email = formData.get('email').trim();
                const password = formData.get('password');
                const confirm_password = formData.get('confirm_password');
                const is_seminar_admin = formData.get('is_seminar_admin') === '1' ? '1' : '0';
                const is_superadmin = formData.get('is_superadmin') === '1' ? '1' : '0';

                // Basic client-side validation
                if (!SUnet || !email || !password || !confirm_password) {
                    addUserMessage.textContent = 'All fields are required.';
                    addUserMessage.className = 'error-message';
                    return;
                }

                if (password !== confirm_password) {
                    addUserMessage.textContent = 'Passwords do not match.';
                    addUserMessage.className = 'error-message';
                    return;
                }

                // Prepare AJAX request
                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'superadmin.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onreadystatechange = function() {
                    if (xhr.readyState === 4) {
                        if (xhr.status === 200) {
                            try {
                                const response = JSON.parse(xhr.responseText);
                                if (response.success) {
                                    // Clear the form
                                    addUserForm.reset();
                                    addUserMessage.textContent = 'User added successfully.';
                                    addUserMessage.className = 'success-message';

                                    // Hide the Add User form
                                    addUserContainer.style.display = 'none';
                                    showAddUserFormButton.style.display = 'block';

                                    // Add the new user to the table
                                    const newUser = response.user;
                                    const newRow = document.createElement('tr');
                                    newRow.id = `user-${newUser.SUnet}`;
                                    newRow.innerHTML = `
                                        <td>${newUser.SUnet}</td>
                                        <td>
                                            <label class="switch">
                                                <input type="checkbox" class="toggle-admin" data-sunet="${newUser.SUnet}" ${newUser.is_seminar_admin ? 'checked' : ''} value="${newUser.is_seminar_admin}">
                                                <span class="slider round"></span>
                                            </label>
                                        </td>
                                        <td>
                                            <label class="switch">
                                                <input type="checkbox" class="toggle-superadmin" data-sunet="${newUser.SUnet}" ${newUser.is_superadmin ? 'checked' : ''} value="${newUser.is_superadmin}">
                                                <span class="slider round"></span>
                                            </label>
                                        </td>
                                        <td>
                                            <button class="delete-button" data-sunet="${newUser.SUnet}">Delete</button>
                                        </td>
                                    `;
                                    usersTableBody.appendChild(newRow);

                                    // Attach event listeners to the new switches and delete button
                                    const newAdminSwitch = newRow.querySelector('.toggle-admin');
                                    const newSuperadminSwitch = newRow.querySelector('.toggle-superadmin');
                                    const newDeleteButton = newRow.querySelector('.delete-button');

                                    newAdminSwitch.addEventListener('change', function() {
                                        const SUnet = this.getAttribute('data-sunet');
                                        const is_seminar_admin = this.checked ? '1' : '0';
                                        const is_superadmin = newRow.querySelector(`.toggle-superadmin[data-sunet="${SUnet}"]`).checked ? '1' : '0';

                                        updatePrivileges(SUnet, is_seminar_admin, is_superadmin);
                                    });

                                    newSuperadminSwitch.addEventListener('change', function() {
                                        const SUnet = this.getAttribute('data-sunet');
                                        const is_superadmin = this.checked ? '1' : '0';
                                        const is_seminar_admin = newRow.querySelector(`.toggle-admin[data-sunet="${SUnet}"]`).checked ? '1' : '0';

                                        updatePrivileges(SUnet, is_seminar_admin, is_superadmin);
                                    });

                                    newDeleteButton.addEventListener('click', function() {
                                        const SUnet = this.getAttribute('data-sunet');
                                        if (confirm(`Are you sure you want to delete user ${SUnet}?`)) {
                                            deleteUser(SUnet);
                                        }
                                    });
                                } else {
                                    addUserMessage.textContent = 'Error: ' + response.message;
                                    addUserMessage.className = 'error-message';
                                }
                            } catch (e) {
                                addUserMessage.textContent = 'Invalid server response.';
                                addUserMessage.className = 'error-message';
                            }
                        } else {
                            addUserMessage.textContent = 'An error occurred while adding the user.';
                            addUserMessage.className = 'error-message';
                        }
                    }
                };

                // Encode form data for URL
                const params = `action=add_user&` + 
                    `SUnet=${encodeURIComponent(SUnet)}` +
                    `&email=${encodeURIComponent(email)}` +
                    `&password=${encodeURIComponent(password)}` +
                    `&confirm_password=${encodeURIComponent(confirm_password)}` +
                    `&is_seminar_admin=${is_seminar_admin}` +
                    `&is_superadmin=${is_superadmin}`;

                xhr.send(params);
            });
        });
    </script>
</body>
</html>
