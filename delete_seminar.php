<?php
session_start();
include('db.php');

// Check if user is logged in and is an admin
if (!isset($_SESSION['user']) || !$_SESSION['is_admin']) {
    header("Location: login.php");
    exit();
}

// Handle deletion
if (isset($_GET['id'])) {
    $seminar_id = $_GET['id'];

    // Delete related disciplines mapping
    $stmt = $conn->prepare("DELETE FROM SeminarDisciplinesMapping WHERE seminar_id = ?");
    $stmt->bind_param('i', $seminar_id);
    $stmt->execute();

    // Delete related tags mapping
    $stmt = $conn->prepare("DELETE FROM SeminarTagsMapping WHERE seminar_id = ?");
    $stmt->bind_param('i', $seminar_id);
    $stmt->execute();

    // Delete related seminar types mapping
    $stmt = $conn->prepare("DELETE FROM SeminarTypeMapping WHERE seminar_id = ?");
    $stmt->bind_param('i', $seminar_id);
    $stmt->execute();

    // Delete seminar itself
    $stmt = $conn->prepare("DELETE FROM Seminars WHERE seminar_id = ?");
    $stmt->bind_param('i', $seminar_id);
    $stmt->execute();

    header("Location: dashboard.php");
    exit();
}

$conn->close();
?>
