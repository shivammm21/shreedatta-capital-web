<?php
// Test script to verify API endpoint
$url = 'http://localhost/shreedatta-capital-web/asset/forms/api/register.php?debug=1';

// Create a minimal test POST request
$postData = [
    'firstName' => 'Test',
    'lastName' => 'User',
    'draw' => 'Test Draw',
    'drawCategory' => 'gold',
    'lang' => 'en',
    'photoData' => 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/2wBDAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwA/8A',
    'tokens' => ['123']
];

// Create files array simulation
$files = [
    'aadFront' => [
        'tmp_name' => __DIR__ . '/test-image.jpg',
        'error' => UPLOAD_ERR_OK,
        'size' => 1024
    ],
    'aadBack' => [
        'tmp_name' => __DIR__ . '/test-image.jpg', 
        'error' => UPLOAD_ERR_OK,
        'size' => 1024
    ]
];

// Create a small test image
file_put_contents(__DIR__ . '/test-image.jpg', base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/2wBDAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwA/8A'));

// Simulate the POST request
$_POST = $postData;
$_FILES = $files;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET['debug'] = '1';

echo "Testing API endpoint directly...\n";
echo "POST data: " . json_encode($postData) . "\n";
echo "Files: " . json_encode(array_keys($files)) . "\n\n";

// Capture output
ob_start();
include __DIR__ . '/asset/forms/api/register.php';
$output = ob_get_clean();

echo "API Response:\n";
echo $output . "\n";

// Clean up
unlink(__DIR__ . '/test-image.jpg');
?>
