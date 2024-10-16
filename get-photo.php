<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\Dotenv\Dotenv;

include 'vendor/autoload.php';

//Getting the environment variable values
$dotenv = new Dotenv();
$dotenv->loadEnv(__DIR__ . '/.env');

[$googleApiKey, $openAiApiKey] = [$_ENV['GOOGLE_API_KEY'], $_ENV['OPENAI_API_KEY']];


// Check if 'reference' parameter is set
if (!isset($_GET['reference'])) {
    header("HTTP/1.1 400 Bad Request");
    echo "Missing 'reference' parameter.";
    exit();
}

$reference = $_GET['reference'];

$client = new Client();

try {
    // Fetch the image from the provided reference URL
    $response = $client->get(
        "https://maps.googleapis.com/maps/api/place/photo?maxwidth=400&photoreference={$reference}&key={$googleApiKey}"
    );

    // Get the Content-Type header from the response
    $contentType = $response->getHeaderLine('Content-Type');

    // Set the Content-Type header for the response
    header("Content-Type: $contentType");

    // Output the image content
    echo $response->getBody();

} catch (GuzzleException $e) {
    // Handle exceptions and return an error message
    header("HTTP/1.1 500 Internal Server Error");
    echo "Error fetching image.";
}
