<?php
/**
 * Http functions
 */

namespace App\Http;

use App\Downloader;
use App\Exceptions\SubscriptionNotActiveException;
use App\Html\Parser;
use App\Utils\Utils;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Event\ProgressEvent;
use Ubench;

/**
 * Class Resolver
 * @package App\Http
 */
class Resolver
{
    /**
     * Guzzle client
     * @var Client
     */
    private $client;

    /**
     * Guzzle cookie
     * @var CookieJar
     */
    private $cookie;

    /**
     * Ubench lib
     * @var Ubench
     */
    private $bench;

    /**
     * Receives dependencies
     *
     * @param Client $client
     * @param Ubench $bench
     */
    public function __construct(Client $client, Ubench $bench)
    {
        $this->client = $client;
        $this->cookie = new CookieJar();
        $this->bench = $bench;
    }

    /**
     * Grabs all lessons & series from the website.
     */
    public function getAllLessons()
    {
        $array = [];
        $html = $this->getAllPage();
        Parser::getAllLessons($html, $array);

        while ($nextPage = Parser::hasNextPage($html)) {
            $html = $this->client->get($nextPage)->getBody()->getContents();
            Parser::getAllLessons($html, $array);
        }

        return $array;
    }

    /**
     * Gets the latest lessons only.
     *
     * @return array
     *
     * @throws \Exception
     */
    public function getLatestLessons()
    {
        $array = [];

        $html = $this->getAllPage();
        Parser::getAllLessons($html, $array);

        return $array;
    }

    /**
     * Gets the html from the all page.
     *
     * @return string
     *
     * @throws \Exception
     */
    private function getAllPage()
    {
        $response = $this->client->get(LARACASTS_ALL_PATH);

        return $response->getBody()->getContents();
    }

    /**
     * Tries to auth.
     *
     * @param $email
     * @param $password
     *
     * @return bool
     * @throws SubscriptionNotActiveException
     */
    public function doAuth($email, $password)
    {
        $response = $this->client->get(LARACASTS_LOGIN_PATH, [
            'cookies' => $this->cookie,
        ]);

        $token = Parser::getToken($response->getBody()->getContents());

        $response = $this->client->post(LARACASTS_POST_LOGIN_PATH, [
            'cookies' => $this->cookie,
            'body'    => [
                'email'    => $email,
                'password' => $password,
                '_token'   => $token,
                'remember' => 1,
            ],
        ]);

        $html = $response->getBody()->getContents();

        if (strpos($html, "Reactivate") !== FALSE) {
            throw new SubscriptionNotActiveException();
        }

        return strpos($html, "verify your credentials.") === FALSE;
    }

    /**
     * Download the episode of the serie.
     *
     * @param $serie
     * @param $episode
     */
    public function downloadSerieEpisode($serie, $episode)
    {
        $path = LARACASTS_SERIES_PATH . '/' . $serie . '/episodes/' . $episode;
        $episodePage = $this->getPage($path);
        $name = $this->getNameOfEpisode($episodePage, $path);
        $number = sprintf("%02d", $episode);
        $saveTo = BASE_FOLDER . '/' . SERIES_FOLDER . '/' . $serie . '/' . $number . '-' . $name . '.mp4';
        Utils::writeln(sprintf("Download started: %s . . . . Saving on " . SERIES_FOLDER . '/' . $serie . ' folder.',
            $number . ' - ' . $name
        ));

        $this->downloadLessonFromPath($episodePage, $saveTo);
    }

    /**
     * Downloads the lesson.
     *
     * @param $lesson
     */
    public function downloadLesson($lesson)
    {
        $path = LARACASTS_LESSONS_PATH . '/' . $lesson;
        $number = sprintf("%04d", ++Downloader::$currentLessonNumber);
        $saveTo = BASE_FOLDER . '/' . LESSONS_FOLDER . '/' . $number . '-' . $lesson . '.mp4';

        Utils::writeln(sprintf("Download started: %s . . . . Saving on " . LESSONS_FOLDER . ' folder.',
            $lesson
        ));
        $html = $this->getPage($path);
        $this->downloadLessonFromPath($html, $saveTo);
    }

    /**
     * Helper function to get html of a page
     * @param $path
     * @return string
     */
    private function getPage($path) {
        return $this->client
            ->get($path, ['cookies' => $this->cookie])
            ->getBody()
            ->getContents();
    }

    /**
     * Helper to get the Location header.
     *
     * @param $url
     *
     * @return string
     */
    private function getRedirectUrl($url)
    {
        $response = $this->client->get($url, [
            'cookies'         => $this->cookie,
            'allow_redirects' => FALSE,
        ]);

        return $response->getHeader('Location');
    }

    /**
     * Gets the name of the serie episode.
     *
     * @param $html
     *
     * @param $path
     * @return string
     */
    private function getNameOfEpisode($html, $path)
    {
        $name = Parser::getNameOfEpisode($html, $path);

        return Utils::parseEpisodeName($name);
    }

    /**
     * Helper to download the video.
     *
     * @param $html
     * @param $saveTo
     */
    private function downloadLessonFromPath($html, $saveTo)
    {
        $downloadUrl = Parser::getDownloadLink($html);

        $viemoUrl = $this->getRedirectUrl($downloadUrl);
        $finalUrl = $this->getRedirectUrl($viemoUrl);

        $this->bench->start();

        $req = $this->client->createRequest('GET', $finalUrl, [
            'save_to' => $saveTo,
        ]);

        if (php_sapi_name() == "cli") { //on cli show progress
            $req->getEmitter()->on('progress', function (ProgressEvent $e) {
                printf("> Total: %d%% Downloaded: %s of %s     \r",
                    Utils::getPercentage($e->downloaded, $e->downloadSize),
                    Utils::formatBytes($e->downloaded),
                    Utils::formatBytes($e->downloadSize));
            });
        }
        $this->client->send($req);

        $this->bench->end();

        Utils::write(sprintf("Elapsed time: %s, Memory: %s",
            $this->bench->getTime(),
            $this->bench->getMemoryUsage()
        ));
    }
}