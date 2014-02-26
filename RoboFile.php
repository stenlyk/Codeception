<?php
require_once __DIR__.'/vendor/autoload.php';

use Symfony\Component\Finder\Finder;
use \Robo\Task\GenMarkdownDocTask as Doc;

class RoboFile extends \Robo\Tasks {

    const STABLE_BRANCH = '1.8';

    public function release()
    {
        $this->say("CODECEPTION RELEASE: ".\Codeception\Codecept::VERSION);
        $this->buildDocs();
        $this->publishSite();
        $this->buildPhar();
        $this->publishPhar();
        $this->publishGit();
    }

    public function update()
    {
        $this->clean();
        $this->taskComposerUpdate()->run();
    }

    protected function server()
    {
        $this->taskServer(8000)
            ->background()
            ->dir('tests/data/app')
            ->run();
    }

    public function testPhpbrowser()
    {
        $this->taskSymfonyCommand(new \Codeception\Command\Run('run'))
            ->arg('suite','tests/unit/Codeception/Module/PhpBrowserTest.php')
            ->run();

    }

    public function testFacebook()
    {
        $this->server();

        $this->taskSymfonyCommand(new \Codeception\Command\Run('run'))
            ->arg('suite','tests/unit/Codeception/Module/FacebookTest.php')
            ->run();

    }

    public function testWebdriver($pathToSelenium = '~/selenium-server-standalone-2.39.0.jar ')
    {
        $this->taskServer(8000)
            ->background()
            ->dir('tests/data/app')
            ->run();

        $this->taskExec('java -jar '.$pathToSelenium)
            ->background()
            ->run();

        $this->taskSymfonyCommand(new \Codeception\Command\Run('run'))
            ->arg('suite','tests/unit/Codeception/Module/WebDriverTest.php')
            ->run();
    }

    public function testCli()
    {
        $this->taskSymfonyCommand(new \Codeception\Command\Run('run'))
            ->arg('suite','cli')
            ->run();

        $this->taskSymfonyCommand(new \Codeception\Command\Run('run'))
            ->arg('suite','tests/unit/Codeception/Command')
            ->run();
    }

    /**
     * @desc creates codecept.phar
     * @throws Exception
     */
    public function buildPhar()
    {
        $pharTask = $this->taskPackPhar('package/codecept.phar')
            ->compress()
            ->stub('package/stub.php');

        $finder = Finder::create()
            ->ignoreVCS(true)
            ->name('*.php')
            ->name('*.tpl.dist')
            ->name('*.html.dist')
            ->in('src');

        foreach ($finder as $file) {
            $pharTask->addFile('src/'.$file->getRelativePathname(), $file->getRealPath());
        }

        $finder = Finder::create()->files()
            ->ignoreVCS(true)
            ->name('*.php')
            ->name('*.css')
            ->name('*.png')
            ->name('*.js')
            ->name('*.css')
            ->name('*.png')
            ->name('*.tpl.dist')
            ->name('*.html.dist')
            ->exclude('Tests')
            ->exclude('tests')
            ->exclude('benchmark')
            ->exclude('demo')
            ->in('vendor');


        foreach ($finder as $file) {
            $pharTask->addStripped('vendor/'.$file->getRelativePathname(), $file->getRealPath());
        }

        $pharTask->addFile('autoload.php', 'autoload.php')
            ->addFile('codecept', 'package/bin')
            ->run();
        
        $code = $this->taskExec('php package/codecept.phar')->run()->getExitCode();
        if ($code !== 0) {
            throw new Exception("There was problem compiling phar");
        }
    }

    /**
     * @desc generates modules reference from source files
     */
    public function buildDocs()
    {
        $this->say('generating documentation from source files');
        $this->buildDocsModules();
        $this->buildDocsUtils();
        $this->buildDocsCommands();
    }

    public function buildDocsModules()
    {
        $this->taskCleanDir('docs/modules')->run();
        $this->say("Modules");
        $modules = Finder::create()->files()->name('*.php')->in(__DIR__ . '/src/Codeception/Module');

        foreach ($modules as $module) {
            $moduleName = basename(substr($module, 0, -4));
            $className = '\Codeception\Module\\' . $moduleName;

            $this->taskGenDoc('docs/modules/' . $moduleName . '.md')
                ->docClass($className)
                ->prepend("# $moduleName Module\n\n**For additional reference, please review the [source](https://github.com/Codeception/Codeception/tree/master/src/Codeception/Module/$moduleName.php)**")
                ->processClass(function($r, $text) {
                    return $text . "\n## Actions\n\n";
                })->filterMethods(function(\ReflectionMethod $method) {
                    if ($method->isConstructor() or $method->isDestructor()) return false;
                    if (!$method->isPublic()) return false;
                    if (strpos($method->name, '_') === 0) return false;
                    return true;
                })->processMethod(function(\ReflectionMethod $method, $text) {
                    $title = "### {$method->name}\n";
                    if (!$text) return $title."__not documented__\n";
                    $text = str_replace(array('@since'), array(' * available since version'), $text);
                    $text = str_replace(array(' @', "\n@"), array("  * ", "\n * "), $text);
                    return $title . $text;
                })->reorderMethods('ksort')
                ->run();
        }
    }

    public function buildDocsUtils()
    {
        $this->say("Util Classes");
        $utils = Finder::create()->files()->name('*.php')->depth(0)->in(__DIR__ . '/src/Codeception/Util');

        foreach ($utils as $util) {
            $utilName = basename(substr($util, 0, -4));
            $className = '\Codeception\Util\\' . $utilName;

            $this->taskGenDoc('docs/utils/' . $utilName . '.md')
                ->docClass($className)
                ->processMethod(function(ReflectionMethod $r, $text) use ($utilName) {
                    $line = $r->getStartLine();
                    $modifiers = implode(' ', \Reflection::getModifierNames($r->getModifiers()));
                    $title = "\n#### *$modifiers* {$r->name}";

                    $text = preg_replace("~@(.*?)([$\s])~",' * `$1` $2', $text);
                    $text .= "\n[See source](https://github.com/Codeception/Codeception/blob/master/src/Codeception/Util/$utilName.php#L$line)";
                    return $title.$text;
                })
                ->reorderMethods('ksort')
                ->run();
        }
    }

    public function buildDocsCommands()
    {
        $this->say("Commands");

        $commands = Finder::create()->files()->name('*.php')->depth(0)->in(__DIR__ . '/src/Codeception/Command');

        $commandGenerator = $this->taskGenDoc('docs/reference/commands.md');
        foreach ($commands as $command) {
            $commandName = basename(substr($command, 0, -4));
            $className = '\Codeception\Command\\' . $commandName;
            $commandGenerator->docClass($className);
        }
        $commandGenerator
            ->prepend("# Console Commands\n")
            ->processClass(function ($r, $text) { $name = $r->getShortName();return "## $name\n$text";  })
            ->filterMethods(function(ReflectionMethod $r) { return false; })
            ->run();

    }

    /**
     * @desc publishes generated phar to codeception.com
     */
    public function publishPhar()
    {
        $this->cloneSite();
        $version = \Codeception\Codecept::VERSION;
        if (strpos($version, self::STABLE_BRANCH) === 0) {
            $this->say("publishing to release branch");
            copy('../codecept.phar','codecept.phar');
            $this->taskExec('git add codecept.phar')->run();
        }

        @mkdir("releases/$version");
        copy('../codecept.phar',"releases/$version/codecept.phar");

        $this->taskExec("git add releases/$version/codecept.phar")->run();
        $this->publishSite();
    }

    /**
     * @desc updates docs on codeception.com
     */
    public function publishDocs()
    {
        if (strpos(\Codeception\Codecept::VERSION, self::STABLE_BRANCH) !== 0) {
            $this->say("The ".\Codeception\Codecept::VERSION." is not in release branch. Site is not build");
            return;
        }
        $this->say('building site...');

        $docs = Finder::create()->files('*.md')->sortByName()->in('docs');
        $this->cloneSite();

        $modules = array();
        $api = array();
        foreach ($docs as $doc) {
            $newfile = $doc->getFilename();
            $name = $doc->getBasename();
            $contents = $doc->getContents();
            if (strpos($doc->getPathname(),'docs'.DIRECTORY_SEPARATOR.'modules')) {
                $newfile = 'docs/modules/'.$newfile;
                $modules[$name] = '/docs/modules/'.$doc->getBasename();
                $contents = str_replace('## ','### ', $contents);
            } else {
                $newfile = 'docs/'.$newfile;
                $api[substr($name,3)] = '/docs/'.$doc->getBasename();
            }

            copy($doc->getPathname(), $newfile);

            $contents = preg_replace('~```\s?php(.*?)```~ms',"{% highlight php %}\n$1\n{% endhighlight %}", $contents);
            $contents = preg_replace('~```\s?html(.*?)```~ms',"{% highlight html %}\n$1\n{% endhighlight %}", $contents);
            $contents = preg_replace('~```(.*?)```~ms',"{% highlight yaml %}\n$1\n{% endhighlight %}", $contents);
            $matches = array();
            $title = "";

            // Extracting page h1 to re-use in <title>
            if (preg_match('/^# (.*)$/m', $contents, $matches)) {
              $title = $matches[1];
            }
            $contents = "---\nlayout: doc\ntitle: ".($title!="" ? $title." - " : "")."Codeception - Documentation\n---\n\n".$contents;
            file_put_contents($newfile, $contents);
        }

        $guides = array_keys($api);
        foreach ($api as $name => $url) {
            $filename = substr($url, 6);
            $doc = file_get_contents('docs/'.$filename.'.md')."\n\n\n";
            $i = array_search($name, $guides);
            if (isset($guides[$i+1])) {
                $next_title = $guides[$i+1];
                $next_url = $api[$guides[$i+1]];
                $doc .= "\n* **Next Chapter: [$next_title >]($next_url)**";
            }

            if (isset($guides[$i-1])) {
                $prev_title = $guides[$i-1];
                $prev_url = $api[$guides[$i-1]];
                $doc .= "\n* **Previous Chapter: [< $prev_title]($prev_url)**";
            }
            file_put_contents('docs/'.$filename.'.md', $doc);
        }


        $guides_list = '';
        foreach ($api as $name => $url) {
            $name = preg_replace('/([A-Z]+)([A-Z][a-z])/', '\\1 \\2', $name);
            $name = preg_replace('/([a-z\d])([A-Z])/', '\\1 \\2', $name);
            $guides_list.= '<li><a href="'.$url.'">'.$name.'</a></li>';
        }

        file_put_contents('_includes/guides.html', $guides_list);

        $modules_list = '';
        foreach ($modules as $name => $url) {
            $modules_list.= '<li><a href="'.$url.'">'.$name.'</a></li>';
        }

        file_put_contents('_includes/modules.html', $modules_list);
        $this->publishSite();
        $this->taskExec('git add')->args('.')->run();
    }

    /**
     * @desc creates a new version tag and pushes to github
     */
    public function publishGit($branch = null)
    {
        $version = \Codeception\Codecept::VERSION;
        $this->say('creating new tag for '.$version);
        if (!$branch) {
            $branch = explode('.', $version);
            array_pop($branch);
            $branch = implode('.',$branch);
        }
        $this->taskExec("git tag $version")->run();
        $this->taskExec("git push origin $branch --tags")->run();
    }

    /**
     * @desc cleans all log and temp directories
     */
    public function clean()
    {
        $this->taskCleanDir([
            'tests/log',
            'tests/data/claypit/tests/_log',
            'tests/data/included/_log',
            'tests/data/included/jazz/tests/_log',
            'tests/data/included/shire/tests/_log',
        ])->run();

        $this->taskDeleteDir([
            'tests/data/claypit/c3tmp',
            'tests/data/sandbox'
        ])->run();
    }

    public function buildGuys()
    {
        $build = 'php codecept build';
        $this->taskExec($build)->run();
        $this->taskExec($build)->args('-c tests/data/claypit')->run();
        $this->taskExec($build)->args('-c tests/data/included')->run();
        $this->taskExec($build)->args('-c tests/data/included/jazz')->run();
        $this->taskExec($build)->args('-c tests/data/included/shire')->run();
        $this->taskExec($build)->args('-c tests/data/included/jazz')->run();
    }

    protected function cloneSite()
    {
        @mkdir("package/site");
        $this->taskExec('git clone')
            ->args('git@github.com:Codeception/codeception.github.com.git')
            ->args('package/site/')
            ->run();
        chdir('package/site');
    }

    protected function publishSite()
    {
        $this->taskExec('git commit')->args('-m "auto updated documentation"')->run();
        $this->taskExec('git push')->run();

        chdir('..');
        sleep(2);
        $this->taskDeleteDir('site')->run();
        chdir('..');
        $this->say("Site build succesfully");
    }

} 