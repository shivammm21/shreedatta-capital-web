<?php
// Check all-submissions table structure
require_once 'asset/db/config.php';

echo "<h1>all-submissions Table Analysis</h1>";

try {
    // Check table structure
    echo "<h2>Table Structure:</h2>";
    $stmt = $pdo->query("DESCRIBE `all-submissions`");
    $columns = $stmt->fetchAll();
    echo "<table border='1'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    foreach ($columns as $col) {
        echo "<tr>";
        echo "<td>" . $col['Field'] . "</td>";
        echo "<td>" . $col['Type'] . "</td>";
        echo "<td>" . $col['Null'] . "</td>";
        echo "<td>" . $col['Key'] . "</td>";
        echo "<td>" . $col['Default'] . "</td>";
        echo "<td>" . $col['Extra'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Check if language column exists
    $hasLanguage = false;
    $hasFormDataId = false;
    foreach ($columns as $col) {
        if ($col['Field'] === 'language') $hasLanguage = true;
        if ($col['Field'] === 'form_data_id') $hasFormDataId = true;
    }
    
    echo "<h3>Column Check:</h3>";
    echo "<p>Language column exists: " . ($hasLanguage ? "✅ Yes" : "❌ No") . "</p>";
    echo "<p>form_data_id column exists: " . ($hasFormDataId ? "✅ Yes" : "❌ No") . "</p>";
    
    // Test insert without language column if it doesn't exist
    if (!$hasLanguage) {
        echo "<h3>Adding language column:</h3>";
        try {
            $pdo->exec("ALTER TABLE `all-submissions` ADD COLUMN `language` VARCHAR(10) DEFAULT 'en'");
            echo "<p style='color: green;'>✅ Language column added successfully</p>";
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Failed to add language column: " . $e->getMessage() . "</p>";
        }
    }
    
    // Check user_docs table
    echo "<h2>user_docs Table Structure:</h2>";
    $stmt = $pdo->query("DESCRIBE user_docs");
    $userDocsColumns = $stmt->fetchAll();
    echo "<table border='1'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    foreach ($userDocsColumns as $col) {
        echo "<tr>";
        echo "<td>" . $col['Field'] . "</td>";
        echo "<td>" . $col['Type'] . "</td>";
        echo "<td>" . $col['Null'] . "</td>";
        echo "<td>" . $col['Key'] . "</td>";
        echo "<td>" . $col['Default'] . "</td>";
        echo "<td>" . $col['Extra'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Check column names for Aadhaar
    $hasCorrectAadhaar = false;
    $hasTypoAadhaar = false;
    foreach ($userDocsColumns as $col) {
        if ($col['Field'] === 'front_addhar' || $col['Field'] === 'back_addhar') $hasCorrectAadhaar = true;
        if ($col['Field'] === 'front_adddhar' || $col['Field'] === 'back_adddhar') $hasTypoAadhaar = true;
    }
    
    echo "<h3>Aadhaar Column Check:</h3>";
    echo "<p>Correct spelling (front_addhar, back_addhar): " . ($hasCorrectAadhaar ? "✅ Yes" : "❌ No") . "</p>";
    echo "<p>Typo spelling (front_adddhar, back_adddhar): " . ($hasTypoAadhaar ? "✅ Yes" : "❌ No") . "</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>