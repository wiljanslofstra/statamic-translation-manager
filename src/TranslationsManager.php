<?php

namespace RyanMitchell\StatamicTranslationManager;

use Brick\VarExporter\ExportException;
use Brick\VarExporter\VarExporter;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RyanMitchell\StatamicTranslationManager\Events\TranslationsSaved;

class TranslationsManager
{
    private array $translations;

    protected Filesystem $filesystem;

    public function __construct(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
        
        if (! $this->filesystem->exists(lang_path())) {
            $this->filesystem->makeDirectory(lang_path());
        }
    }

    public function getLocales(): array
    {
        $locales = collect();

        foreach ($this->filesystem->directories(lang_path()) as $dir) {
            if (Str::contains($dir, 'vendor')) {
                continue;
            }

            $locales->push([
                'name' => basename($dir)
            ]);
        }

        return $locales->toArray();
    }

    public function getTranslations(): array
    {
        collect($this->filesystem->allFiles(lang_path()))
            ->filter(function ($file) {
                return ! in_array($file->getFilename(), config('statamic-translations.exclude_files', []));
            })
            ->filter(function ($file) {
                return $this->filesystem->extension($file) == 'php' || $this->filesystem->extension($file) == 'json';
            })
            ->each(function ($file) {
                try {
                    $locale = $file->getRelativePath();
                    if ($this->filesystem->extension($file) == 'php') {
                        $strings = array_dot($this->filesystem->getRequire($file->getPathname()));
                    }

                    if ($this->filesystem->extension($file) == 'json') {
                        $strings = json_decode($this->filesystem->get($file), true);
                        $strings = Arr::dot($strings);
                    }
                    
                    foreach ($strings as $key => $string) {
                        $namespace = Str::before($file->getFilename(), '.');
                        
                        if (! in_array($namespace, config('statamic-translations.exclude_namespaces', []))) {
                            if (is_string($string)) {
                                $this->translations[] = [
                                    'file' => $namespace,
                                    'locale' => $locale ?: $namespace,
                                    'key' => $key,
                                    'string' => $string,
                                ];
                            }
                        }
                    }
                } catch (FileNotFoundException $e) {
                    //
                }
            });

        return $this->translations;
    }

    public function saveTranslations(string $locale, array $translations): void
    {
        foreach ($translations as $namespace => $phrases) {
            $phrases = collect($phrases)
                ->mapWithKeys(fn ($phrase) => [$phrase['key'] => $phrase['string']])
                ->all();
                            
            $phrases = Arr::undot($phrases);

            $path = lang_path("{$locale}/{$namespace}.json");

            if (! $this->filesystem->isDirectory(dirname($path))) {
                $this->filesystem->makeDirectory(dirname($path), 0755, true);
            }

            if (! $this->filesystem->exists($path)) {
                $path = lang_path("{$locale}/{$namespace}.php");
                
                if (! $this->filesystem->exists($path)) {
                    $this->filesystem->put($path, "<?php\n\nreturn [\n\n]; ".PHP_EOL);
                }
            }

            if ($this->filesystem->extension($path) == 'php') {
                try {
                    $this->filesystem->put($path, "<?php\n\nreturn ".VarExporter::export($phrases, VarExporter::TRAILING_COMMA_IN_ARRAY).';'.PHP_EOL);
                } catch (ExportException $e) {
                    logger()->error($e->getMessage());
                }
            }

            if ($this->filesystem->extension($path) == 'json') {
                $this->filesystem->put($path, json_encode($phrases, JSON_PRETTY_PRINT));
            }
            
            TranslationsSaved::dispatch($locale, $namespace, $translations);
        }
    }
}