<?php

declare(strict_types=1);

namespace App;

require 'vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

// Load environment variables
$dotenv = new Dotenv();
$dotenv->loadEnv(__DIR__ . '/.env');

// Instantiate the main application and run it
$app = new Application($_ENV['GOOGLE_API_KEY'], $_ENV['OPENAI_API_KEY']);
$app->run();

//////////////////////
// Application Class
//////////////////////

namespace App;

use App\Controller\CafeFinderController;

class Application
{
    private string $googleApiKey;
    private string $openAiApiKey;

    public function __construct(string $googleApiKey, string $openAiApiKey)
    {
        $this->googleApiKey = $googleApiKey;
        $this->openAiApiKey = $openAiApiKey;
    }

    public function run(): void
    {
        $controller = new CafeFinderController($this->googleApiKey, $this->openAiApiKey);
        $controller->handleRequest();
    }
}

//////////////////////////////
// Controller Class
//////////////////////////////

namespace App\Controller;

use App\Client\GooglePlacesClient;
use App\Client\OpenAIChatClient;
use App\Model\Place;
use App\View\Renderer;

class CafeFinderController
{
    private GooglePlacesClient $googleClient;
    private OpenAIChatClient $openAIClient;
    private Renderer $renderer;

    public function __construct(string $googleApiKey, string $openAiApiKey)
    {
        $this->googleClient = new GooglePlacesClient($googleApiKey);
        $this->openAIClient = new OpenAIChatClient($openAiApiKey);
        $this->renderer = new Renderer();
    }

    public function handleRequest(): void
    {
        // Get input parameters
        $location = $_GET['location'] ?? '47.487915018023436,19.068177992485133';
        $keyword = $_GET['keyword'] ?? 'speciality';
        $type = $_GET['type'] ?? 'cafe';
        $userPrompt = $_GET['prompt'] ?? '';


        // Render the appropriate view
        if ($this->isAjaxRequest()) {

            // Fetch places from Google Places API
            $placesData = $this->googleClient->searchPlaces($location, $keyword, $type);
            $topPlaces = array_slice($placesData['results'], 0, 5);

            // Process places and get descriptions from OpenAI
            $places = [];
            foreach ($topPlaces as $placeData) {
                $placeDetails = $this->googleClient->getPlaceDetails($placeData['place_id']);
                $description = $this->openAIClient->getPlaceDescription($placeDetails, $userPrompt);
                $photoUrl = $this->googleClient->getPhotoUrl($placeData);

                $places[] = new Place(
                    $placeData['name'],
                    $photoUrl,
                    $description
                );
            }

            echo $this->renderer->renderPlaceCards($places);
        } else {
            //Render page without the place cards
            echo $this->renderer->renderPage([], [
                'location' => $location,
                'keyword' => $keyword,
                'type' => $type,
                'prompt' => $userPrompt
            ]);
        }
    }

    private function isAjaxRequest(): bool
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
}

//////////////////////////////
// GooglePlacesClient Class
//////////////////////////////

namespace App\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class GooglePlacesClient
{
    private string $apiKey;
    private Client $client;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
        $this->client = new Client();
    }

    public function searchPlaces(string $location, string $keyword, string $type): array
    {
        $url = 'https://maps.googleapis.com/maps/api/place/nearbysearch/json';
        $params = [
            'location' => $location,
            'radius' => 500,
            'type' => $type,
            'keyword' => $keyword,
            'key' => $this->apiKey
        ];

        return $this->makeGetRequest($url, $params);
    }

    public function getPlaceDetails(string $placeId): array
    {
        $url = 'https://maps.googleapis.com/maps/api/place/details/json';
        $params = [
            'place_id' => $placeId,
            'fields' => 'name,rating,formatted_phone_number,reviews,opening_hours',
            'key' => $this->apiKey
        ];

        return $this->makeGetRequest($url, $params)['result'] ?? [];
    }

    public function getPhotoUrl(array $placeData): string
    {
        if (isset($placeData['photos'][0]['photo_reference'])) {
            $photoReference = $placeData['photos'][0]['photo_reference'];
            return "/get-photo.php?reference={$photoReference}";
        }
        return 'no_image.png'; // Placeholder image
    }

    private function makeGetRequest(string $url, array $params): array
    {
        try {
            $response = $this->client->get($url, ['query' => $params]);
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            // Handle exception or log error
            return [];
        }
    }
}

//////////////////////////////
// OpenAIChatClient Class
//////////////////////////////

namespace App\Client;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException as HttpException;

class OpenAIChatClient
{
    private string $apiKey;
    private GuzzleClient $client;
    private string $defaultPrompt;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
        $this->client = new GuzzleClient();
        $this->defaultPrompt = file_get_contents('default_prompt.txt');
    }

    public function getPlaceDescription(array $placeDetails, string $userPrompt): string
    {
        $messages = [
            ['role' => 'system', 'content' => $this->defaultPrompt],
            ['role' => 'system', 'content' => 'Információk a helyről (JSON formátumban): ' . json_encode($placeDetails)]
        ];

        if (!empty($userPrompt)) {
            $messages[] = ['role' => 'user', 'content' => $userPrompt];
        }

        try {
            $response = $this->client->post('https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => "Bearer {$this->apiKey}"
                ],
                'json' => [
                    'model'    => 'gpt-4o',
                    'messages' => $messages
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            return $data['choices'][0]['message']['content'] ?? '';
        } catch (HttpException $e) {
            // Handle exception or log error
            return 'Description not available.';
        }
    }
}

//////////////////////////////
// Place Model Class
//////////////////////////////

namespace App\Model;

class Place
{
    private string $name;
    private string $photo;
    private string $description;

    public function __construct(string $name, string $photo, string $description)
    {
        $this->name        = $name;
        $this->photo       = $photo;
        $this->description = $description;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPhoto(): string
    {
        return $this->photo;
    }

    public function getDescription(): string
    {
        return $this->description;
    }
}

//////////////////////////////
// Renderer Class
//////////////////////////////

namespace App\View;

use App\Model\Place;

class Renderer
{
    public function renderPlaceCards(array $places): string
    {
        $html = '';
        foreach ($places as $place) {
            /**
             * @var Place $place
             */
            $html .= '<div class="flex items-start border rounded-lg p-4 mb-4">';
            $html .= '<img class="w-32 h-32 object-cover rounded-lg mr-4" src="' . htmlspecialchars($place->getPhoto()) . '" alt="Cafe Image">';
            $html .= '<div class="flex-1">';
            $html .= '<h3 class="text-xl font-semibold mb-2">' . htmlspecialchars($place->getName()) . '</h3>';
            $html .= '<div class="text-gray-700">' . htmlspecialchars($place->getDescription()) . '</div>';
            $html .= '</div></div>';
        }
        return $html;
    }

    public function renderPage(array $places, array $params): string
    {
        $placeCardsHtml = $this->renderPlaceCards($places);
        ob_start();
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>Top Cafes</title>
            <script src="https://cdn.tailwindcss.com"></script>
        </head>
        <body>
        <div class="container mx-auto p-4">
            <h1 class="text-3xl font-bold mb-4">Top Cafes Nearby</h1>
            <form id="searchForm" class="mb-8">
                <div class="flex flex-wrap -mx-2">
                    <div class="w-full md:w-1/2 px-2 mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="location">
                            Location (latitude,longitude)
                        </label>
                        <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="location" name="location" type="text" value="<?= htmlspecialchars($params['location']) ?>" placeholder="Enter location">
                    </div>
                    <div class="w-full md:w-1/4 px-2 mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="keyword">
                            Keyword
                        </label>
                        <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="keyword" name="keyword" type="text" value="<?= htmlspecialchars($params['keyword']) ?>" placeholder="Enter keyword">
                    </div>
                    <div class="w-full md:w-1/4 px-2 mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="type">
                            Type
                        </label>
                        <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="type" name="type" type="text" value="<?= htmlspecialchars($params['type']) ?>" placeholder="Enter Type">
                    </div>
                    <div class="w-full px-2 mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="prompt">
                            Prompt
                        </label>
                        <textarea class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" id="prompt" name="prompt" rows="3" placeholder="Enter additional instructions"><?= htmlspecialchars($params['prompt']) ?></textarea>
                    </div>
                    <div class="w-full px-2">
                        <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline">
                            Search
                        </button>
                    </div>
                </div>
            </form>
            <div id="results">
                <?= $placeCardsHtml ?>
            </div>
        </div>
        <script>
            document.getElementById('searchForm').addEventListener('submit', function(e) {
                e.preventDefault();
                // Collect form data
                const formData = new FormData(this);
                // Prepare query parameters
                const params = new URLSearchParams();
                for (const pair of formData.entries()) {
                    params.append(pair[0], pair[1]);
                }
                // Show loading indicator
                document.getElementById('results').innerHTML = '<p class="text-gray-500">Loading...</p>';
                // Send AJAX request
                fetch('<?= $_SERVER['PHP_SELF'] ?>?' + params.toString(), {
                    method: 'GET',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                    .then(response => response.text())
                    .then(data => {
                        // Update the results div
                        document.getElementById('results').innerHTML = data;
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        document.getElementById('results').innerHTML = '<p class="text-red-500">An error occurred. Please try again.</p>';
                    });
            });
        </script>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
}