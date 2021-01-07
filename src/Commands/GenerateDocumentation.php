<?php

namespace Knuckles\Scribe\Commands;

use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Knuckles\Camel\Tools\Loader;
use Knuckles\Camel\Tools\Serialiser;
use Knuckles\Scribe\Extracting\Extractor;
use Knuckles\Scribe\Matching\MatchedRoute;
use Knuckles\Scribe\Matching\RouteMatcherInterface;
use Knuckles\Scribe\Tools\ConsoleOutputUtils as c;
use Knuckles\Scribe\Tools\DocumentationConfig;
use Knuckles\Scribe\Tools\ErrorHandlingUtils as e;
use Knuckles\Scribe\Tools\Globals;
use Knuckles\Scribe\Tools\Utils;
use Knuckles\Scribe\Tools\Utils as u;
use Knuckles\Scribe\Writing\Writer;
use Mpociot\Reflection\DocBlock;
use Mpociot\Reflection\DocBlock\Tag;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\Yaml\Yaml;

class GenerateDocumentation extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = "scribe:generate
                            {--force : Discard any changes you've made to the Markdown files}
                            {--no-extraction : Skip extraction of route info and just transform the Markdown files}
    ";

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate API documentation from your Laravel/Dingo routes.';

    /**
     * @var DocumentationConfig
     */
    private $docConfig;

    /**
     * @var string
     */
    private $baseUrl;

    /**
     * Execute the console command.
     *
     * @param RouteMatcherInterface $routeMatcher
     *
     * @return void
     */
    public function handle(RouteMatcherInterface $routeMatcher)
    {
        $this->bootstrap();

        $noExtraction = $this->option('no-extraction');
        $camelDir = ".endpoints";

        if (!$noExtraction) {
            $routes = $routeMatcher->getRoutes($this->docConfig->get('routes'), $this->docConfig->get('router'));
            $endpoints = $this->extractEndpointsInfo($routes);
            $serialised = Serialiser::serialiseEndpointsForOutput($endpoints);

            // Utils::deleteDirectoryAndContents($comparisonDir);

            if (!is_dir($camelDir)) {
                mkdir($camelDir);
            }

            $i = 0;
            foreach ($serialised as $groupName => $endpointsInGroup) {
                file_put_contents(
                    "$camelDir/$i.yaml",
                    Yaml::dump(
                        $endpointsInGroup,
                        10,
                        2,
                        Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK
                    )
                );
                $i++;
            }
        }

        $endpoints = Loader::loadEndpoints($camelDir);

        $writer = new Writer($this->docConfig, $this->option('force'));
        $writer->writeDocs($endpoints);
    }

    /**
     * @param MatchedRoute[] $matches
     *
     * @return array
     *
     */
    private function extractEndpointsInfo(array $matches): array
    {
        $generator = new Extractor($this->docConfig);
        $parsedRoutes = [];
        foreach ($matches as $routeItem) {
            $route = $routeItem->getRoute();

            $routeControllerAndMethod = u::getRouteClassAndMethodNames($route);
            if (!$this->isValidRoute($routeControllerAndMethod)) {
                c::warn('Skipping invalid route: ' . c::getRouteRepresentation($route));
                continue;
            }

            if (!$this->doesControllerMethodExist($routeControllerAndMethod)) {
                c::warn('Skipping route: ' . c::getRouteRepresentation($route) . ' - Controller method does not exist.');
                continue;
            }

            if ($this->isRouteHiddenFromDocumentation($routeControllerAndMethod)) {
                c::warn('Skipping route: ' . c::getRouteRepresentation($route) . ': @hideFromAPIDocumentation was specified.');
                continue;
            }

            try {
                c::info('Processing route: ' . c::getRouteRepresentation($route));
                $parsedRoutes[] = $generator->processRoute($route, $routeItem->getRules());
                c::success('Processed route: ' . c::getRouteRepresentation($route));
            } catch (\Exception $exception) {
                c::error('Failed processing route: ' . c::getRouteRepresentation($route) . ' - Exception encountered.');
                e::dumpExceptionIfVerbose($exception);
            }
        }

        return $parsedRoutes;
    }

    private function isValidRoute(array $routeControllerAndMethod = null): bool
    {
        if (is_array($routeControllerAndMethod)) {
            [$classOrObject, $method] = $routeControllerAndMethod;
            if (u::isInvokableObject($classOrObject)) {
                return true;
            }
            $routeControllerAndMethod = $classOrObject . '@' . $method;
        }

        return !is_callable($routeControllerAndMethod) && !is_null($routeControllerAndMethod);
    }

    private function doesControllerMethodExist(array $routeControllerAndMethod): bool
    {
        [$class, $method] = $routeControllerAndMethod;
        $reflection = new ReflectionClass($class);

        if ($reflection->hasMethod($method)) {
            return true;
        }

        return false;
    }

    private function isRouteHiddenFromDocumentation(array $routeControllerAndMethod): bool
    {
        if (!($class = $routeControllerAndMethod[0]) instanceof \Closure) {
            $classDocBlock = new DocBlock((new ReflectionClass($class))->getDocComment() ?: '');
            $shouldIgnoreClass = collect($classDocBlock->getTags())
                ->filter(function (Tag $tag) {
                    return Str::lower($tag->getName()) === 'hidefromapidocumentation';
                })->isNotEmpty();

            if ($shouldIgnoreClass) {
                return true;
            }
        }

        $methodDocBlock = new DocBlock(u::getReflectedRouteMethod($routeControllerAndMethod)->getDocComment() ?: '');
        $shouldIgnoreMethod = collect($methodDocBlock->getTags())
            ->filter(function (Tag $tag) {
                return Str::lower($tag->getName()) === 'hidefromapidocumentation';
            })->isNotEmpty();

        return $shouldIgnoreMethod;
    }

    public function bootstrap(): void
    {
        // Using a global static variable here, so 🙄 if you don't like it.
        // Also, the --verbose option is included with all Artisan commands.
        Globals::$shouldBeVerbose = $this->option('verbose');

        c::bootstrapOutput($this->output);

        $this->docConfig = new DocumentationConfig(config('scribe'));
        $this->baseUrl = $this->docConfig->get('base_url') ?? config('app.url');

        // Force root URL so it works in Postman collection
        URL::forceRootUrl($this->baseUrl);
    }
}
