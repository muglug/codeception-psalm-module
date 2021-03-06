<?php

declare(strict_types=1);

namespace Weirdan\Codeception\Psalm;

use Codeception\Exception\ModuleRequireException;
use Codeception\Exception\TestRuntimeException;
use Codeception\Module as BaseModule;
use Codeception\Module\Cli;
use Codeception\Module\Filesystem;
use Codeception\TestInterface;
use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use Muglug\PackageVersions\Versions as LegacyVersions;
use PackageVersions\Versions;
use PHPUnit\Framework\Assert;
use Behat\Gherkin\Node\TableNode;
use PHPUnit\Framework\SkippedTestError;
use RuntimeException;

class Module extends BaseModule
{
    /** @var array<string,string */
    private const VERSION_OPERATORS = [
        'newer than' => '>',
        'older than' => '<',
    ];

    private const DEFAULT_PSALM_CONFIG = "<?xml version=\"1.0\"?>\n"
        . "<psalm totallyTyped=\"true\" %s>\n"
        . "  <projectFiles>\n"
        . "    <directory name=\".\"/>\n"
        . "  </projectFiles>\n"
        . "</psalm>\n";

    /**
     * @var ?Cli
     */
    private $cli;

    /**
     * @var ?Filesystem
     */
    private $fs;

    /** @var array<string,string> */
    protected $config = [
        'psalm_path' => 'vendor/bin/psalm',
        'default_file' => 'somefile.php',
        'default_dir' => 'tests/_run/',
    ];

    /** @var string */
    private $psalmConfig = '';

    /** @var string */
    private $preamble = '';

    /** @var ?array<int, array{type:string,message:string}> */
    private $errors = null;

    /** @var bool */
    private $hasAutoload = false;

    /** @var ?int */
    private $exitCode = null;

    /** @var ?string */
    protected $output = null;

    public function _beforeSuite($configuration = []): void
    {
        $defaultDir = $this->config['default_dir'];
        if (file_exists($defaultDir)) {
            if (is_dir($defaultDir)) {
                return;
            }
            unlink($defaultDir);
        }

        if (!mkdir($defaultDir, 0755, true)) {
            throw new TestRuntimeException('Failed to create dir: ' . $defaultDir);
        }
    }

    public function _before(TestInterface $test): void
    {
        $this->hasAutoload = false;
        $this->errors = null;
        $this->output = null;
        $this->exitCode = null;
        $this->config['psalm_path'] = realpath($this->config['psalm_path']);
        $this->psalmConfig = '';
        $this->fs()->cleanDir($this->config['default_dir']);
        $this->preamble = '';
    }

    /**
     * @param string[] $options
     */
    public function runPsalmOn(string $filename, array $options = []): void
    {
        $suppressProgress = $this->seePsalmVersionIs('>=', '3.4.0');

        $options = array_map('escapeshellarg', $options);
        $cmd = $this->config['psalm_path']
                . ' --output-format=json '
                . ($suppressProgress ? ' --no-progress ' : ' ')
                . join(' ', $options) . ' '
                . ($filename ? escapeshellarg($filename) : '')
                . ' 2>&1';
        $this->debug('Running: ' . $cmd);
        $this->cli()->runShellCommand($cmd, false);

        /** @psalm-suppress MissingPropertyType shouldn't be required, but older Psalm needs it */
        $this->output = (string)$this->cli()->output;
        /** @psalm-suppress MissingPropertyType shouldn't be required, but older Psalm needs it */
        $this->exitCode = (int)$this->cli()->result;

        $this->debug(sprintf('Psalm exit code: %d', $this->exitCode));
        // $this->debug('Psalm output: ' . $this->output);
    }

    /**
     * @param string[] $options
     */
    public function runPsalmIn(string $dir, array $options = []): void
    {
        $pwd = getcwd();
        $this->fs()->amInPath($dir);

        $config = $this->psalmConfig ?: self::DEFAULT_PSALM_CONFIG;
        $config = sprintf($config, $this->hasAutoload ? 'autoloader="autoload.php"' : '');

        $this->fs()->writeToFile('psalm.xml', $config);

        $this->runPsalmOn('', $options);
        $this->fs()->amInPath($pwd);
    }

    /**
     * @Then I see exit code :code
     */
    public function seeExitCode(int $exitCode): void
    {
        if ($this->exitCode === $exitCode) {
            return;
        }

        Assert::fail("Expected exit code {$exitCode}, got {$this->exitCode}");
    }

    public function seeThisError(string $type, string $message): void
    {
        $this->parseErrors();
        if (empty($this->errors)) {
            Assert::fail("No errors");
        }

        foreach ($this->errors as $i => $error) {
            if ($error['type'] === $type && $this->messageMatches($message, $error['message'])) {
                unset($this->errors[$i]);
                return;
            }
        }

        Assert::fail("Didn't see [ $type $message ] in: \n" . $this->remainingErrors());
    }

    private function messageMatches(string $expected, string $actual): bool
    {
        $regexpDelimiter = '/';
        if ($expected[0] === $regexpDelimiter && $expected[strlen($expected) - 1] === $regexpDelimiter) {
            $regexp = $expected;
        } else {
            $regexp = $this->convertToRegexp($expected);
        }

        return (bool) preg_match($regexp, $actual);
    }

    /**
     * @Then I see no errors
     * @Then I see no other errors
     */
    public function seeNoErrors(): void
    {
        $this->parseErrors();
        if (!empty($this->errors)) {
            Assert::fail("There were errors: \n" . $this->remainingErrors());
        }
    }

    public function seePsalmVersionIs(string $operator, string $version): bool
    {
        $currentVersion = $this->getShortVersion('vimeo/psalm');

        $this->debug(sprintf("Current version: %s", $currentVersion));

        // todo: move to init/construct/before?
        $parser = new VersionParser();
        $currentVersion =  $parser->normalize($currentVersion);

        // restore pre-composer/semver:2.0 behaviour for comparison purposes
        if (preg_match('/^dev-/', $currentVersion)) {
            $currentVersion = '9999999-dev';
        }

        $version = $parser->normalize($version);

        $result = Comparator::compare($currentVersion, $operator, $version);
        $this->debug("Comparing $currentVersion $operator $version => $result");

        return $result;
    }

    /**
     * @Given I have the following code preamble :code
     */
    public function haveTheFollowingCodePreamble(string $code): void
    {
        $this->preamble = $code;
    }

    /**
     * @When I run psalm
     * @When I run Psalm
     */
    public function runPsalm(): void
    {
        $this->runPsalmIn($this->config['default_dir']);
    }

    /**
     * @When I run Psalm with dead code detection
     */
    public function runPsalmWithDeadCodeDetection(): void
    {
        $this->runPsalmIn($this->config['default_dir'], ['--find-dead-code']);
    }

    /**
     * @When I run Psalm on :arg1
     * @When I run psalm on :arg1
     */
    public function runPsalmOnASingleFile(string $file): void
    {
        $pwd = getcwd();
        $this->fs()->amInPath($this->config['default_dir']);

        $config = $this->psalmConfig ?: self::DEFAULT_PSALM_CONFIG;
        $config = sprintf($config, $this->hasAutoload ? 'autoloader="autoload.php"' : '');

        $this->fs()->writeToFile('psalm.xml', $config);

        $this->runPsalmOn($file);
        $this->fs()->amInPath($pwd);
    }


    /**
     * @Given I have the following config :config
     */
    public function haveTheFollowingConfig(string $config): void
    {
        $this->psalmConfig = $config;
    }

    /**
     * @Given I have the following code :code
     */
    public function haveTheFollowingCode(string $code): void
    {
        $file = rtrim($this->config['default_dir'], '/') . '/' . $this->config['default_file'];
        $this->fs()->writeToFile(
            $file,
            $this->preamble . $code
        );
    }

    /**
     * @Given I have some future Psalm that supports this feature :ref
     */
    public function haveSomeFuturePsalmThatSupportsThisFeature(string $ref): void
    {
        /** @psalm-suppress InternalClass */
        throw new SkippedTestError("Future functionality that Psalm has yet to support: $ref");
    }

    /**
     * @Given /I have Psalm (newer than|older than) "([0-9.]+)" \(because of "([^"]+)"\)/
     */
    public function havePsalmOfACertainVersionRangeBecauseOf(string $operator, string $version, string $reason): void
    {
        if (!isset(self::VERSION_OPERATORS[$operator])) {
            throw new TestRuntimeException("Unknown operator: $operator");
        }

        $op = (string) self::VERSION_OPERATORS[$operator];

        if (!$this->seePsalmVersionIs($op, $version)) {
            /** @psalm-suppress InternalClass */
            throw new SkippedTestError("This scenario requires Psalm $op $version because of $reason");
        }
    }

    /**
     * @Then I see these errors
     */
    public function seeTheseErrors(TableNode $list): void
    {
        /** @psalm-suppress MixedAssignment */
        foreach (array_values($list->getRows()) as $i => $error) {
            assert(is_array($error));
            if (0 === $i) {
                continue;
            }
            $this->seeThisError((string) $error[0], (string) $error[1]);
        }
    }

    /**
     * @Given I have the following code in :arg1 :arg2
     */
    public function haveTheFollowingCodeIn(string $filename, string $code): void
    {
        $file = rtrim($this->config['default_dir'], '/') . '/' . $filename;
        $this->fs()->writeToFile($file, $code);
    }

    /**
     * @Given I have the following autoload map
     * @Given I have the following classmap
     * @Given I have the following class map
     */
    public function haveTheFollowingAutoloadMap(TableNode $list): void
    {
        $map = [];
        /** @psalm-suppress MixedAssignment */
        foreach (array_values($list->getRows()) as $i => $row) {
            assert(is_array($row));
            if (0 === $i) {
                continue;
            }
            assert(is_string($row[0]));
            assert(is_string($row[1]));
            $map[] = [$row[0], $row[1]];
        }

        $code = sprintf(
            '<?php
            spl_autoload_register(function(string $class) {
                /** @var ?array<string,string> $classes */
                static $classes = null;
                if (null === $classes) {
                    $classes = [%s];
                }
                if (array_key_exists($class, $classes)) {
                    /** @psalm-suppress UnresolvableInclude */
                    include $classes[$class];
                }
            });',
            join(
                ',',
                array_map(
                    function (array $row): string {
                        return "\n'$row[0]' => '$row[1]'";
                    },
                    $map
                )
            )
        );
        $file = rtrim($this->config['default_dir'], '/') . '/' . 'autoload.php';
        $this->fs()->writeToFile($file, $code);
        $this->hasAutoload = true;
    }

    private function convertToRegexp(string $in): string
    {
        return '@' . str_replace('%', '.*', preg_quote($in, '@')) . '@';
    }

    private function cli(): Cli
    {
        if (null === $this->cli) {
            $cli = $this->getModule('Cli');
            if (!$cli instanceof Cli) {
                throw new ModuleRequireException($this, 'Needs Cli module');
            }
            $this->cli = $cli;
        }
        return $this->cli;
    }

    private function fs(): Filesystem
    {
        if (null === $this->fs) {
            $fs = $this->getModule('Filesystem');
            if (!$fs instanceof Filesystem) {
                throw new ModuleRequireException($this, 'Needs Filesystem module');
            }
            $this->fs = $fs;
        }
        return $this->fs;
    }

    private function remainingErrors(): string
    {
        $this->parseErrors();
        return (string) new TableNode(array_map(
            function (array $error): array {
                return [
                    'type' => $error['type'] ?? '',
                    'message' => $error['message'] ?? '',
                ];
            },
            $this->errors
        ));
    }

    private function getShortVersion(string $package): string
    {
        if (class_exists(Versions::class)) {
            /** @psalm-suppress UndefinedClass psalm 3.0 ignores class_exists check */
            $version = (string) Versions::getVersion($package);
        } elseif (class_exists(LegacyVersions::class)) {
            $version = (string) LegacyVersions::getVersion($package);
        } else {
            throw new RuntimeException(
                'Neither muglug/package-versions-56 nor ocramius/package-version is available,'
                . ' cannot determine versions'
            );
        }

        if (false === strpos($version, '@')) {
            throw new RuntimeException('$version must contain @');
        }

        return explode('@', $version)[0];
    }

    /**
     * @psalm-assert !null $this->errors
     */
    private function parseErrors(): void
    {
        if (null !== $this->errors) {
            return;
        }

        if (empty($this->output)) {
            $this->errors = [];
            return;
        }

        /** @psalm-suppress MixedAssignment */
        $errors = json_decode($this->output, true);

        if (null === $errors && json_last_error() !== JSON_ERROR_NONE && 0 !== $this->exitCode) {
            Assert::fail("Failed to parse output: " . $this->output . "\nError:" . json_last_error_msg());
        }

        $this->errors = array_map(
            function (array $row): array {
                return [
                    'type' => (string) $row['type'],
                    'message' => (string) $row['message'],
                ];
            },
            array_values((array)$errors)
        );
        $this->debug($this->remainingErrors());
    }
}
