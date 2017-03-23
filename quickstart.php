<?php
require_once __DIR__ . '/vendor/autoload.php';

define('APPLICATION_NAME', 'Espeo Google Slides Generator');
define('CREDENTIALS_PATH', '~/.credentials/espeo.google-slide-api-tutorial.json');
define('CLIENT_SECRET_PATH', __DIR__ . '/client_secret.json');
define('TEMPLATE_NAME', 'Espeo template');
define('DRIVE_API_FILES_ENDPOINT', 'https://www.googleapis.com/drive/v3/files');
// If modifying these scopes, delete your previously saved credentials
// at ~/.credentials/espeo.google-slide-api-tutorial.json
define('SCOPES', [
        Google_Service_Slides::PRESENTATIONS,
        Google_Service_Slides::DRIVE
]);

if (php_sapi_name() !== 'cli') {
    throw new Exception('This application must be run on the command line.');
}

/**
 * Returns an authorized API client.
 * @return Google_Client the authorized client object
 * @throws Exception
 */
function getClient()
{
    $client = new Google_Client();
    $client->setApplicationName(APPLICATION_NAME);
    $client->setScopes(SCOPES);
    $client->setAuthConfig(CLIENT_SECRET_PATH);
    $client->setAccessType('offline');

    // Load previously authorized credentials from a file.
    $credentialsPath = expandHomeDirectory(CREDENTIALS_PATH);
    if (file_exists($credentialsPath)) {
        if (file_get_contents($credentialsPath) === false){
            throw new Exception('Cannot get file contents!');
        }
        $accessToken = json_decode(file_get_contents($credentialsPath), true);
    } else {
        // Request authorization from the user.
        $authUrl = $client->createAuthUrl();
        printf("Open the following link in your browser:\n%s\n", $authUrl);
        print 'Enter verification code: ';
        $authCode = trim(fgets(STDIN));

        // Exchange authorization code for an access token.
        $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);

        if(isset($accessToken['error'])){
            throw new InvalidArgumentException("Wrong verification code ({$accessToken['error']} - {$accessToken['error_description']})");
        }

        // Store the credentials to disk.
        if(!file_exists(dirname($credentialsPath))) {
            mkdir(dirname($credentialsPath), 0700, true);
        }
        if(file_put_contents($credentialsPath, json_encode($accessToken)) === false){
            throw new Exception("Cannot save $credentialsPath");
        }
        printf("Credentials saved to %s\n", $credentialsPath);
    }

    $client->setAccessToken($accessToken);

    // Refresh the token if it's expired.
    if ($client->isAccessTokenExpired()) {
        $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
        if(file_put_contents($credentialsPath, json_encode($client->getAccessToken())) === false)
            throw new Exception("Cannot save $credentialsPath");
    }

    return $client;
}

/**
 * Expands the home directory alias '~' to the full path.
 * @param string $path the path to expand.
 * @return string the expanded path.
 */
function expandHomeDirectory($path)
{
    $homeDirectory = getenv('HOME');
    if ($homeDirectory === false) {
        $homeDirectory = getenv('HOMEDRIVE') . getenv('HOMEPATH');
    }

    return str_replace('~', realpath($homeDirectory), $path);
}

/**
 * Returns the id of cloned presentation
 * @param Google_Service_Drive $driveService
 * @param string $copyName the name for cloned presentation
 * @return string cloned presentation id
 * @throws Exception
 */
function clonePresentationByName(Google_Service_Drive $driveService, $copyName)
{
    $response = $driveService->files->listFiles([
        'q' => "mimeType='application/vnd.google-apps.presentation' and name='".TEMPLATE_NAME."'",
        'spaces' => 'drive',
        'fields' => 'files(id, name)',
    ]);
    if($response->files){
        $templatePresentationId = $response->files[0]->id;
    } else {
        throw new Exception('Template presentation not found');
    }

    $copy = new Google_Service_Drive_DriveFile([
        'name' => $copyName
    ]);
    $driveResponse = $driveService->files->copy($templatePresentationId, $copy);

    return $driveResponse->id;
}

/**
 * Returns and url for uploaded image
 * @param Google_Service_Drive $driveService
 * @param string $imagePath path to local file
 * @param string|null $name new name of image for Google Drive
 * @return string uploaded image url
 */
function uploadImage(Google_Service_Drive $driveService, $imagePath, $name = null)
{

    $file = new Google_Service_Drive_DriveFile([
        'name' => $name ? $name : basename($imagePath),
        'mimeType' => image_type_to_mime_type(exif_imagetype($imagePath))
    ]);
    $params = [
        'data' => file_get_contents($imagePath),
        'uploadType' => 'media',
    ];
    $upload = $driveService->files->create($file, $params);
    $fileId = $upload->id;

    $token = $driveService->getClient()->getAccessToken()['access_token'];
    $imageUrl = sprintf('%s/%s?alt=media&access_token=%s', DRIVE_API_FILES_ENDPOINT, $fileId, $token);

    return $imageUrl;
}

function requestReplaceText($placeholder, $replacement)
{
    return new Google_Service_Slides_Request([
        'replaceAllText' => [
            'containsText' => [
                'text' => $placeholder,
                'matchCase' =>  true,
            ],
            'replaceText' => $replacement
        ]
    ]);
}

function requestReplaceShapesWithImage($shapeText, $imageUrl)
{
    return new Google_Service_Slides_Request([
        'replaceAllShapesWithImage' => [
            'containsText' => [
                'text' => $shapeText,
                'matchCase' =>  true,
            ],
            'imageUrl' => $imageUrl,
            'replaceMethod' => 'CENTER_INSIDE',
        ]
    ]);
}

function executeRequests(Google_Service_Slides $slidesService, $presentationId, $requests)
{
    $batchUpdateRequest = new Google_Service_Slides_BatchUpdatePresentationRequest([
        'requests' => $requests
    ]);

    $slidesService->presentations->batchUpdate($presentationId, $batchUpdateRequest);
}

function replaceContent(Google_Service_Slides $slidesService, $presentationId, $imageUrl)
{
    $requests = [];

    $requests[] = requestReplaceText('{{ product_name }}', 'Awesome name');
    $requests[] = requestReplaceText('{{ product_description }}', 'Some description');
    $requests[] = requestReplaceShapesWithImage('{{ image }}', $imageUrl);

    executeRequests($slidesService, $presentationId, $requests);
}

function downloadAsPdf(Google_Service_Drive $driveService, $presentationId)
{
    $response = $driveService->files->export($presentationId, 'application/pdf');
    $content = $response->getBody();
    $pdfPath = './pdf/result.pdf';
    if(file_put_contents($pdfPath, $content) === false){
        throw new Exception("Cannot save pdf at $pdfPath");
    }
}

function main()
{
    $client = getClient();
    $driveService = new Google_Service_Drive($client);
    $slidesService = new Google_Service_Slides($client);

    $presentationId = clonePresentationByName($driveService, 'copy_name');
    $imageUrl = uploadImage($driveService, './images/espeo.png');

    replaceContent($slidesService, $presentationId, $imageUrl);
    downloadAsPdf($driveService, $presentationId);
}

main();