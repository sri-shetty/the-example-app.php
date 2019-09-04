<?php

/**
 * This file is part of the contentful/the-example-app package.
 *
 * @copyright 2015-2018 Contentful GmbH
 * @license   MIT
 */

declare(strict_types=1);

namespace App\Service;

use Contentful\Core\Api\Exception;
use Contentful\Delivery\Client;
use Contentful\Delivery\Query;
use Contentful\Delivery\Resource\Entry;
use Contentful\Delivery\Resource\Environment;
use Contentful\Delivery\Resource\Space;

/**
 * Contentful class.
 *
 * This class acts as a wrapper around the Content Delivery SDK.
 * It implements special logic for handling dual clients (for Preview and Delivery APIs).
 */
class Contentful
{
    /**
     * @var string
     */
    public const API_DELIVERY = 'cda';

    /**
     * @var string
     */
    public const API_PREVIEW = 'cpa';

    /**
     * @var string
     */
    public const COOKIE_SETTINGS_NAME = 'theExampleAppSettings';

    /**
     * @var Client
     */
    private $client;

    /**
     * @var State
     */
    private $state;

    /**
     * @var EntryStateChecker
     */
    private $entryStateChecker;

    /**
     * @var ClientFactory
     */
    private $clientFactory;

    /**
     * @param State             $state
     * @param ClientFactory     $clientFactory
     * @param EntryStateChecker $entryStateChecker
     */
    public function __construct(State $state, ClientFactory $clientFactory, EntryStateChecker $entryStateChecker)
    {
        $this->state = $state;
        $this->entryStateChecker = $entryStateChecker;
        $this->clientFactory = $clientFactory;
        $this->client = $clientFactory->createClient(
            $this->state->isDeliveryApi() ? self::API_DELIVERY : self::API_PREVIEW
        );
    }

    /**
     * Validates the given credentials by trying to make an API call.
     *
     * @param string $spaceId
     * @param string $accessToken
     * @param string $api
     *
     * @throws Exception if the credentials are not valid and an error response is returned from Contentful
     */
    public function validateCredentials(string $spaceId, string $accessToken, string $api = self::API_DELIVERY): void
    {
        // We make an "empty" API call,
        // the result of which will depend on the validity of the credentials.
        // If any error should arise, the call will throw an exception.
        $query = (new Query())
            ->setLimit(1)
        ;
        $this->clientFactory->createClient($api, $spaceId, $accessToken, false)->getContentTypes($query);
    }

    /**
     * Finds the space object currently in use.
     *
     * @return Space
     */
    public function findSpace(): Space
    {
        return $this->client->getSpace();
    }

    /**
     * Finds the environment object currently in use.
     *
     * @return Environment
     */
    public function findEnvironment(): Environment
    {
        return $this->client->getEnvironment();
    }

    /**
     * Finds the available courses, sorted alphabetically.
     *
     * @return Entry[]
     */
    public function findCategories(): array
    {
        $query = (new Query())
            ->setContentType('category')
            ->orderBy('fields.title')
            ->setLocale($this->state->getLocale())
        ;

        return $this->client->getEntries($query)->getItems();
    }

    /**
     * Finds the available courses, sorted by creation date.
     *
     * @param Entry|null $category
     *
     * @return Entry[]
     */
    public function findCourses(?Entry $category): array
    {
        $query = (new Query())
            ->setContentType('course')
            ->setLocale($this->state->getLocale())
            ->orderBy('-sys.createdAt')
            ->setInclude(2)
        ;

        if ($category) {
            $query->where('fields.categories.sys.id', $category->getId());
        }

        $courses = $this->client->getEntries($query)->getItems();

        if ($courses && $this->state->hasEditorialFeaturesLink()) {
            $this->entryStateChecker->computeState(...$courses);
        }

        return $courses;
    }

    /**
     * Finds a course using its slug.
     * We use the collection endpoint, so we can prefetch linked entries
     * by using the include parameter.
     * Depending on the page, we can choose whether we can go as deep as
     * the lesson modules when working out the entry state.
     *
     * @param string $courseSlug
     *
     * @return Entry|null
     */
    public function findCourse(string $courseSlug): ?Entry
    {
        $course = $this->findEntry('course', $courseSlug);

        if ($course && $this->state->hasEditorialFeaturesLink()) {
            $this->entryStateChecker->computeState($course);
        }

        return $course;
    }

    /**
     * Even if the main goal of the query is to get a lesson, we look for the course instead.
     * This is done to take advantage of the include operator, which allows us to get
     * a whole tree of entries, which is useful because we also need to get
     * some data from the next lesson, too.
     * In order to simplify access, we attach the lesson and nextLesson objects
     * to the main course one.
     *
     * @param string $courseSlug
     * @param string $lessonSlug
     *
     * @return Entry|null
     */
    public function findCourseByLesson(string $courseSlug, string $lessonSlug): ?Entry
    {
        $course = $this->findEntry('course', $courseSlug, 3);
        if (!$course) {
            return null;
        }

        $lessons = $course->get('lessons');
        $lessonIndex = $this->findLessonIndex($lessons, $lessonSlug);
        if (null === $lessonIndex) {
            return null;
        }

        $course->lesson = $lessons[$lessonIndex];
        $course->nextLesson = $lessons[$lessonIndex + 1] ?? null;

        if ($this->state->hasEditorialFeaturesLink()) {
            $course->lesson->children = $course->lesson->get('modules');
            $this->entryStateChecker->computeState($course->lesson);
        }

        return $course;
    }

    /**
     * @param Entry[] $lessons
     * @param string  $lessonSlug
     *
     * @return int|null
     */
    private function findLessonIndex(array $lessons, string $lessonSlug): ?int
    {
        foreach ($lessons as $index => $lesson) {
            if ($lesson->get('slug') === $lessonSlug) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Finds a landing page using its slug.
     * We use the collection endpoint, so we can prefetch linked entries
     * by using the include parameter.
     *
     * @param string $slug
     *
     * @return Entry|null
     */
    public function findLandingPage(string $slug): ?Entry
    {
        $landingPage = $this->findEntry('layout', $slug, 3);

        if ($landingPage && $this->state->hasEditorialFeaturesLink()) {
            $landingPage->children = $landingPage->get('contentModules');
            $this->entryStateChecker->computeState($landingPage);
        }

        return $landingPage;
    }

    /**
     * @param string $contentType
     * @param string $slug
     * @param int    $include
     *
     * @return Entry|null
     */
    private function findEntry(string $contentType, string $slug, int $include = 1): ?Entry
    {
        $query = (new Query())
            ->setLocale($this->state->getLocale())
            ->setContentType($contentType)
            ->where('fields.slug', $slug)
            ->setInclude($include)
            ->setLimit(1)
        ;

        return $this->client->getEntries($query)->getItems()[0] ?? null;
    }
}
