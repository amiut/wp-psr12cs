<?php

declare(strict_types=1);

namespace Amiut\WpPsr12\Sniffs\Autoload;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use RuntimeException;
use SlevomatCodingStandard\Helpers\ClassHelper;

final class Psr4Sniff implements Sniff
{
    const CODE_INCORRECT_CLASS_NAME = 'IncorrectClassName';

    /**
     * @var string
     */
    private $composerJsonPath = 'composer.json';

    public function register(): array
    {
        return [T_CLASS, T_INTERFACE, T_TRAIT];
    }

    public function process(File $phpcsFile, $stackPtr)
    {
        $composerJsonPath = $this->resolveComposerJsonPath($phpcsFile);
        $autoLoaders = $this->psr4AutoLoaders($composerJsonPath);

        // Add a warning if no psr-4 autoloader is defined in composer.json
        if (!$autoLoaders) {
            $phpcsFile->addWarning(
                sprintf(
                    'No Autoloaders registered in your composer.json file ' .
                    'If you do not use Psr-4 autoloaders, ' .
                    'you should disable rule: %s',
                    'NoAutoloaders' // TODO: Better code
                ),
                $stackPtr,
                'NoAutoloaders' // TODO: Better code
            );
            return;
        }

        $classFqcn = ClassHelper::getFullyQualifiedName($phpcsFile, $stackPtr);

        if ($this->isAutoloadable($phpcsFile, $autoLoaders, $classFqcn)) {
            return;
        }

        $phpcsFile->addError(
            'Class name is not compliant with PSR-4 configuration.',
            $phpcsFile->findNext(T_STRING, $stackPtr + 1, null, false),
            self::CODE_INCORRECT_CLASS_NAME
        );
    }

    private function isAutoloadable(File $phpcsFile, array $autoLoaders, string $fqcn): bool
    {
        $fileName = $phpcsFile->getFilename();

        foreach ($autoLoaders as $vendorNameSpace => $basePath) {
            $basePathPosition = strpos($fileName, $basePath);

            if ($basePathPosition === false) {
                continue;
            }

            $relativePath = $this->relativePath($fileName, $basePath);
            $relativeFqcn = trim(
                str_replace(
                    '\\',
                    '/',
                    str_replace(
                        $vendorNameSpace,
                        '',
                        $fqcn
                    )
                ),
                '/'
            );

            if ($relativeFqcn === $relativePath) {
                return true;
            }
        }

        return false;
    }

    private function relativePath(string $fileName, string $basePath): string
    {
        $basePathPosition = strpos($fileName, $basePath);

        $path = str_replace(
            $basePath,
            '',
            substr($fileName, $basePathPosition, strlen($fileName))
        );

        return pathinfo($path, PATHINFO_DIRNAME)
            . DIRECTORY_SEPARATOR
            . pathinfo($path, PATHINFO_FILENAME);
    }

    private function resolveComposerJsonPath(File $phpcsFile): string
    {
        $basePath = $basePath = $phpcsFile->config !== null
            ? ($phpcsFile->config->getSettings()['basepath'] ?? '.')
            : '.';

        return $basePath . '/' . $this->composerJsonPath;
    }

    private function psr4AutoLoaders(string $composerJsonPath): array
    {
        /** @var array|null $autoLoaders */
        static $autoLoaders = null;

        if ($autoLoaders !== null) {
            return $autoLoaders;
        }

        if (!is_readable($composerJsonPath)) {
            throw new RuntimeException(
                sprintf('composer.json not found or not readable: %s', $composerJsonPath)
            );
        }

        $composerJsonContent = file_get_contents($composerJsonPath);

        if (!$composerJsonContent) {
            throw new RuntimeException(
                sprintf('Could not load composer.json file: %s', $composerJsonPath)
            );
        }

        $data = json_decode($composerJsonContent, true);

        if (!is_array($data)) {
            throw new RuntimeException(
                sprintf('Could not parse composer.json file: %s', $composerJsonPath)
            );
        }

        $autoLoaders = array_merge(
            $data['autoload']['psr-4'] ?? [],
            $data['autoload-dev']['psr-4'] ?? []
        );

        return $autoLoaders;
    }
}
