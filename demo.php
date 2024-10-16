<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

include 'vendor/autoload.php';

//Getting the environment variable values
$dotenv = new Dotenv();
$dotenv->loadEnv(__DIR__ . '/.env');

[$googleApiKey, $openAiApiKey] = [$_ENV['GOOGLE_API_KEY'], $_ENV['OPENAI_API_KEY']];


header('Content-Type: application/json');


$client = new \GuzzleHttp\Client();
$request = new \GuzzleHttp\Psr7\Request('GET',
    'https://maps.googleapis.com/maps/api/place/nearbysearch/json?location=47.487915018023436, 19.068177992485133&radius=500&type=cafe&keyword=speciality&key=' .  $googleApiKey);
$res = $client->sendAsync($request)->wait();

echo $res->getBody()->getContents();
