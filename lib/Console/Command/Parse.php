<?php
/*
 * This file is part of mesamatrix.
 *
 * Copyright (C) 2014-2017 Romain "Creak" Failliot.
 * Copyright (C) 2014 Robin McCorkell <rmccorkell@karoshi.org.uk>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Mesamatrix\Console\Command;

use \Symfony\Component\Console\Input\InputInterface;
use \Symfony\Component\Console\Input\InputOption;
use \Symfony\Component\Console\Output\OutputInterface;
use \Mesamatrix\Parser\Constants;
use \Mesamatrix\Parser\Parser;
use \Mesamatrix\Parser\Matrix;
use \Mesamatrix\Parser\ApiVersion;
use \Mesamatrix\Parser\OglExtension;
use \Mesamatrix\Parser\UrlCache;
use \Mesamatrix\Parser\Hints;

/**
 * Class that handles the 'parse' command.
 *
 * It reads all the commits of the file features.txt and transform it to an XML
 * file. The entry point for this class is the `execute()` method.
 */
class Parse extends \Symfony\Component\Console\Command\Command
{
    protected $output;
    protected $statuses;
    protected $urlCache;

    protected function configure() {
        $this->setName('parse')
             ->setDescription('Parse data and generate XML')
             ->addOption('force', '-f',
                         InputOption::VALUE_NONE,
                         'Force to parse all the commits again')
             ->addOption('regenerate-xml', '-r',
                         InputOption::VALUE_NONE,
                         'Regenerate the XML based on the already parsed commits');
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $force = $input->getOption('force');
        $regenXml = $input->getOption('regenerate-xml');

        $this->output = $output;
        $this->statuses = array(
            'complete' => 'DONE*',
            'incomplete' => 'not started*',
            'started' => '*');

        // Get commits for each files in gl_filepaths.
        $commits = array();
        $glFilepaths = \Mesamatrix::$config->getValue('git', 'gl_filepaths', array());
        foreach ($glFilepaths as $filepath) {
            $fileCommits = $this->fetchCommits($filepath['name'], $filepath['excluded_commits']);
            if ($fileCommits !== NULL) {
                foreach ($fileCommits as $commit) {
                    $commits[] = $commit;
                }
            }
        }
        unset($glFilepaths);

        if (empty($commits)) {
            // No commit found, exit.
            return 1;
        }

        $numCommits = count($commits);

        $lastCommitFilename = \Mesamatrix::path(\Mesamatrix::$config->getValue('info', 'private_dir', 'private'))
                            . '/last_commit_parsed';

        // Get last commit parsed.
        $lastCommitParsed = "";
        if (!$force) {
            if (file_exists($lastCommitFilename)) {
                $h = fopen($lastCommitFilename, "r");
                if ($h !== false) {
                    $lastCommitParsed = fgets($h);
                    fclose($h);
                }
            }
        }
        else {
            \Mesamatrix::$logger->info('Commits parsing forced.');
        }

        // Get last commit fetched.
        $lastCommitFetched = $commits[$numCommits - 1]->getHash();

        // Compare last parsed and fetched commits.
        \Mesamatrix::$logger->debug("Last commit fetched: ${lastCommitFetched}");
        \Mesamatrix::$logger->debug("Last commit parsed:  ${lastCommitParsed}");
        if ($lastCommitFetched === $lastCommitParsed) {
            \Mesamatrix::$logger->info("No new commit, no need to parse.");
            if (!$regenXml)
                return 0;
        }

        // Ensure existence of the commits directory.
        $commitsDir = \Mesamatrix::path(\Mesamatrix::$config->getValue('info', 'private_dir'))
                   . '/commits';
        if (!is_dir($commitsDir)) {
            if (!mkdir($commitsDir)) {
                \Mesamatrix::$logger->critical('Couldn\'t create directory `'.$commitsDir.'`.');
                return 1;
            }
        }

        // Get list of new commits to parse.
        $firstNewCommitIdx = 0;
        if (!empty($lastCommitParsed)) {
            // Find index of the last parsed commit in the commit list.
            // It can't be the last one since we verified they are different.
            $i = 0;
            while ($i < $numCommits - 1 && $commits[$i]->getHash() !== $lastCommitParsed) {
                ++$i;
            }

            $firstNewCommitIdx = $i + 1;
        }

        $newCommits = array_slice($commits, $firstNewCommitIdx);
        \Mesamatrix::$logger->info("New commit(s) found: ".count($newCommits).".");

        $this->loadUrlCache();

        // Parse each new commit.
        foreach ($newCommits as $commit) {
            $this->parseCommit($commit);
        }

        $this->saveUrlCache();

        // Merge all the commits.
        $this->generateMergedXml($commits);

        // Save last commit parsed.
        $h = fopen($lastCommitFilename, "w");
        if ($h !== false) {
            fwrite($h, $lastCommitFetched);
            fclose($h);
        }
    }

    /**
     * Fetch commits from mesa's git.
     *
     * @param string $filepath GL text file path.
     * @param string[] $excludedCommits The commits to exclude from the list.
     * @return \Mesamatrix\Git\Commit[]|null Array of commits.
     */
    protected function fetchCommits($filepath, array $excludedCommits) {
        $branch = \Mesamatrix::$config->getValue("git", "branch");
        $gitCommitGet = new \Mesamatrix\Git\Process(array(
            'rev-list', $branch, '--reverse', '--', $filepath
        ));
        $gitCommitGet->mustRun();
        $oldestCommit = strtok($gitCommitGet->getOutput(), "\n");

        if (empty($oldestCommit)) {
            \Mesamatrix::$logger->info('No oldest commit found for '.$filepath);
            return NULL;
        }

        \Mesamatrix::$logger->info('Oldest commit for '.$filepath.': '.$oldestCommit);

        $logSeparator = uniqid('mesamatrix_separator_');
        $logFormat = implode(PHP_EOL, [$logSeparator, '%H', '%at', '%aN', '%cN', '%ct', '%s']);
        $gitLog = new \Mesamatrix\Git\Process(array(
            'log', '--pretty=format:'.$logFormat, '--reverse', '-p',
            $oldestCommit.'..', '--', $filepath
        ));
        $this->getHelper('process')->mustRun($this->output, $gitLog);

        $commitSections = explode($logSeparator . PHP_EOL, $gitLog->getOutput());
        if (empty($commitSections)) {
            // No commit? There must be a problem.
            \Mesamatrix::$logger->error("No commit found.");
            return NULL;
        }

        // Create commit list.
        $commits = array();
        foreach ($commitSections as $commitSection) {
            $commitData = explode(PHP_EOL, $commitSection, 7);

            if ($commitData !== FALSE && isset($commitData[1])) {
                $commitHash = $commitData[0];

                // Skip excluded commits.
                if (in_array($commitHash, $excludedCommits))
                    continue;

                $commit = new \Mesamatrix\Git\Commit();
                $commit->setFilepath($filepath)
                       ->setHash($commitHash)
                       ->setDate($commitData[1])
                       ->setAuthor($commitData[2])
                       ->setCommitter($commitData[3])
                       ->setCommitterDate($commitData[4])
                       ->setSubject($commitData[5])
                       ->setData('<pre>'.$commitData[6].'</pre>');
                $commits[] = $commit;
            }
        }

        return $commits;
    }

    /**
     * Parse a commit.
     *
     * @param \Mesamatrix\Git\Commit $commit The commit to parse.
     */
    protected function parseCommit(\Mesamatrix\Git\Commit $commit) {
        // Show content for this commit.
        $filepath = $commit->getFilepath();
        $hash = $commit->getHash();
        $cat = new \Mesamatrix\Git\Process(array('show', $hash.':'.$filepath));
        $this->getHelper('process')->mustRun($this->output, $cat);

        // Parse the content.
        \Mesamatrix::$logger->info('Parsing '.(basename($filepath)).' for commit '.$hash);
        $parser = new Parser();
        $matrix = $parser->parseContent($cat->getOutput());

        // Create the XML.
        $xml = new \SimpleXMLElement("<mesa></mesa>");

        // Write commit info.
        $xmlCommit = $xml->addChild('commit');
        $xmlCommit->addChild('filepath', $filepath);
        $xmlCommit->addChild('hash', $hash);
        //$xmlCommit->addChild('subject', $commit->getSubject());
        $xmlCommit->addChild('author', $commit->getAuthor());
        $xmlCommit->addChild('committer-date', $commit->getCommitterDate()->format(\DateTime::W3C));
        $xmlCommit->addChild('committer-name', $commit->getCommitter());

        // Write APIs.
        $apis = $xml->addChild('apis');

        // Write OpenGL API.
        $api = $apis->addChild('api');
        $api->addAttribute('name', Constants::GL_NAME);
        $this->generateApiVersions($api, $matrix, Constants::GL_NAME);

        // Write OpenGL ES API.
        $api = $apis->addChild('api');
        $api->addAttribute('name', Constants::GLES_NAME);
        $this->generateApiVersions($api, $matrix, Constants::GLES_NAME);

        // Write OpenGL(ES) extra API.
        $api = $apis->addChild('api');
        $api->addAttribute('name', Constants::GL_OR_ES_EXTRA_NAME);
        $this->generateApiVersions($api, $matrix, Constants::GL_OR_ES_EXTRA_NAME);

        // Write Vulkan API.
        $api = $apis->addChild('api');
        $api->addAttribute('name', Constants::VK_NAME);
        $this->generateApiVersions($api, $matrix, Constants::VK_NAME);

        // Write Vulkan extra API.
        $api = $apis->addChild('api');
        $api->addAttribute('name', Constants::VK_EXTRA_NAME);
        $this->generateApiVersions($api, $matrix, Constants::VK_EXTRA_NAME);

        // Write OpenCL API.
        $api = $apis->addChild('api');
        $api->addAttribute('name', Constants::OPENCL_NAME);
        $this->generateApiVersions($api, $matrix, Constants::OPENCL_NAME);

        // Write OpenCL extra API.
        $api = $apis->addChild('api');
        $api->addAttribute('name', Constants::OPENCL_EXTRA_NAME);
        $this->generateApiVersions($api, $matrix, Constants::OPENCL_EXTRA_NAME);

        // Write OpenCL vendor-specific API.
        $api = $apis->addChild('api');
        $api->addAttribute('name', Constants::OPENCL_VENDOR_SPECIFIC_NAME);
        $this->generateApiVersions($api, $matrix, Constants::OPENCL_VENDOR_SPECIFIC_NAME);

        // Write file.
        $xmlPath = \Mesamatrix::path(\Mesamatrix::$config->getValue('info', 'private_dir'))
                   . '/commits/commit_'.$hash.'.xml';
        $dom = dom_import_simplexml($xml)->ownerDocument;
        $dom->formatOutput = true;

        file_put_contents($xmlPath, $dom->saveXML());
        \Mesamatrix::$logger->info('XML saved to '.$xmlPath);
    }

    /**
     * Take all the parsed commits and merged them to generate the final XML.
     *
     * @param array \Mesamatrix\Git\Commit $commits The commits to merge.
     */
    protected function generateMergedXml(array $commits) {
        if (count($commits) == 0) {
            \Mesamatrix::$logger->error('No commits to merge.');
            return;
        }

        \Mesamatrix::$logger->info('Merge all the commits.');

        // Get latest commit, this will be our base.
        $latestCommit = $commits[count($commits) - 1];

        $hash = $latestCommit->getHash();
        $xmlPath = \Mesamatrix::path(\Mesamatrix::$config->getValue('info', 'private_dir'))
                 . '/commits/commit_'.$hash.'.xml';
        $mesa = simplexml_load_file($xmlPath);

        $matrix = new Matrix();
        $matrix->loadXml($mesa);

        $nextCommit = $latestCommit;
        for ($i = count($commits) - 2; $i >= 0; --$i) {
            $commit = $commits[$i];

            $hash = $commit->getHash();
            $xmlPath = \Mesamatrix::path(\Mesamatrix::$config->getValue('info', 'private_dir'))
                     . '/commits/commit_'.$hash.'.xml';
            $mesa = simplexml_load_file($xmlPath);

            $prevMatrix = new Matrix();
            $prevMatrix->loadXml($mesa);

            $this->compareMatricesAndSetModificationCommit($prevMatrix, $matrix, $nextCommit);

            $nextCommit = $commit;
        }

        \Mesamatrix::$logger->info('Generating XML file');
        $xml = new \SimpleXMLElement("<mesa></mesa>");

        // Get time of last fetch.
        $gitDir = \Mesamatrix::path(\Mesamatrix::$config->getValue('info', 'private_dir')).'/';
        $gitDir .= \Mesamatrix::$config->getValue('git', 'mesa_dir', 'mesa.git');
        $fetchHeadPath = $gitDir . '/FETCH_HEAD';
        if (!file_exists($fetchHeadPath)) {
            // If FETCH_HEAD doesn't exists, fallback on HEAD.
            $fetchHeadPath = $gitDir . '/HEAD';
        }
        $updated = filemtime($fetchHeadPath);
        $xml->addAttribute('updated', $updated);

        $statuses = $xml->addChild("statuses");
        $this->populateStatuses($statuses);

        $xmlCommits = $xml->addChild('commits');
        $this->generateCommitsLog($xmlCommits, $commits);

        // Generate APIs.
        $apis = $xml->addChild('apis');

        // Generate for OpenGL.
        $api = $apis->addChild('api');
        $api->addAttribute('name', Constants::GL_NAME);
        $this->populateGlVendors($api);
        $this->generateApiVersions($api, $matrix, Constants::GL_NAME);

        // Generate for OpenGL ES.
        $api = $apis->addChild('api');
        $api->addAttribute('name', Constants::GLES_NAME);
        $this->populateGlVendors($api); // Uses the same drivers as OpenGL.
        $this->generateApiVersions($api, $matrix, Constants::GLES_NAME);

        // Generate for OpenGL/ES (extra).
        $api = $apis->addChild('api');
        $api->addAttribute('name', Constants::GL_OR_ES_EXTRA_NAME);
        $this->populateGlVendors($api); // Uses the same drivers as OpenGL.
        $this->generateApiVersions($api, $matrix, Constants::GL_OR_ES_EXTRA_NAME);

        // Generate for Vulkan.
        $api = $apis->addChild('api');
        $api->addAttribute('name', Constants::VK_NAME);
        $this->populateVulkanVendors($api);
        $this->generateApiVersions($api, $matrix, Constants::VK_NAME);

        // Generate for Vulkan (extra).
        $api = $apis->addChild('api');
        $api->addAttribute('name', Constants::VK_EXTRA_NAME);
        $this->populateVulkanVendors($api);
        $this->generateApiVersions($api, $matrix, Constants::VK_EXTRA_NAME);

        // Generate for OpenCL.
        $api = $apis->addChild('api');
        $api->addAttribute('name', Constants::OPENCL_NAME);
        $this->populateOpenClVendors($api);
        $this->generateApiVersions($api, $matrix, Constants::OPENCL_NAME);

        // Generate for OpenCL (extra).
        $api = $apis->addChild('api');
        $api->addAttribute('name', Constants::OPENCL_EXTRA_NAME);
        $this->populateOpenClVendors($api);
        $this->generateApiVersions($api, $matrix, Constants::OPENCL_EXTRA_NAME);

        // Generate for OpenCL (vendor specific).
        $api = $apis->addChild('api');
        $api->addAttribute('name', Constants::OPENCL_VENDOR_SPECIFIC_NAME);
        $this->populateOpenClVendors($api);
        $this->generateApiVersions($api, $matrix, Constants::OPENCL_VENDOR_SPECIFIC_NAME);

        $xmlPath = \Mesamatrix::path(\Mesamatrix::$config->getValue("info", "xml_file"));

        $dom = dom_import_simplexml($xml)->ownerDocument;
        $dom->formatOutput = true;
        file_put_contents($xmlPath, $dom->saveXML());

        \Mesamatrix::$logger->info('XML saved to '.$xmlPath);
    }

    /**
     * Compare the two matrices, when a difference is seen, sets the commit as
     * the one that modified the extension and/or its drivers.
     *
     * @param Matrix $prevMatrix The previous matrix.
     * @param Matrix $matrix The current matrix.
     * @param \Mesamatrix\Git\Commit $commit The commit inducing the changes
     *                                       from previous to current matrix.
     */
    private function compareMatricesAndSetModificationCommit(
        Matrix $prevMatrix,
        Matrix $matrix,
        \Mesamatrix\Git\Commit $commit) {
        foreach ($matrix->getApiVersions() as $apiVersion) {
            foreach ($apiVersion->getExtensions() as $ext) {
                $prevExt = $prevMatrix->getExtensionBySubstr($ext->getName());

                $this->compareExtensionsAndSetModificationCommit($prevExt, $ext, $commit);
            }
        }
    }

    /**
     * Compare the two extensions, when a difference is seen, sets the commit
     * as the one that modified the extension and/or its drivers.
     *
     * @param OglExtension $prevExt The previous extension.
     * @param OglExtension $ext The current extension.
     * @param Commit $commit The commit inducing the changes from previous to
     *                       current extension.
     */
    private function compareExtensionsAndSetModificationCommit(
        ?OglExtension $prevExt,
        OglExtension $ext,
        \Mesamatrix\Git\Commit $commit) {

        if ($ext->getModifiedAt() === null) {
            if ($prevExt === null ||
                $ext->getStatus() !== $prevExt->getStatus() ||
                $ext->getHint() !== $prevExt->getHint()) {
                // Extension didn't exist before or status/hint changed.
                $ext->setModifiedAt($commit);
            }
        }

        // Supported drivers.
        foreach ($ext->getSupportedDrivers() as $driver) {
            if ($driver->getModifiedAt() !== null) {
                continue;
            }

            $prevDriver = $prevExt !== null ?
                $prevExt->getSupportedDriverByName($driver->getName()) :
                null;
            if ($prevDriver === null ||
                $driver->getHint() !== $prevDriver->getHint()) {
                $driver->setModifiedAt($commit);
            }
        }

        // Sub-extensions.
        foreach ($ext->getSubExtensions() as $subExt) {
            $prevSubExt = $prevExt !== null ?
                $prevExt->findSubExtensionByName($subExt->getName()) :
                null;

            $this->compareExtensionsAndSetModificationCommit($prevSubExt, $subExt, $commit);
        }
    }

    protected function populateStatuses(\SimpleXMLElement $xmlStatuses) {
        foreach ($this->statuses as $status => $match) {
            $xmlStatus = $xmlStatuses->addChild($status);
            $xmlStatus->addChild('match', $match);
        }
    }

    protected function generateCommitsLog(\SimpleXMLElement $xml, array $commits) {
        foreach (array_reverse($commits) as $commit) {
            $commitNode = $xml->addChild("commit", htmlspecialchars($commit->getData()));
            $commitNode->addAttribute("hash", $commit->getHash());
            $commitNode->addAttribute("timestamp", $commit->getCommitterDate()->getTimestamp());
            $commitNode->addAttribute("subject", $commit->getSubject());
        }
    }

    protected function populateGlVendors(\SimpleXMLElement $xmlParent) {
        $xmlVendors = $xmlParent->addChild("vendors");
        $this->populateDrivers($xmlVendors, Constants::GL_ALL_DRIVERS_VENDORS);
    }

    protected function populateVulkanVendors(\SimpleXMLElement $xmlParent) {
        $xmlVendors = $xmlParent->addChild("vendors");
        $this->populateDrivers($xmlVendors, Constants::VK_ALL_DRIVERS_VENDORS);
    }

    protected function populateOpenClVendors(\SimpleXMLElement $xmlParent) {
        $xmlVendors = $xmlParent->addChild("vendors");
        $this->populateDrivers($xmlVendors, Constants::OPENCL_ALL_DRIVERS_VENDORS);
    }

    protected function populateDrivers(\SimpleXMLElement $xmlVendors, array $vendors) {
        foreach ($vendors as $vendor => $drivers) {
            $xmlVendor = $xmlVendors->addChild("vendor");
            $xmlVendor->addAttribute("name", $vendor);
            $xmlDrivers = $xmlVendor->addChild("drivers");
            foreach ($drivers as $driver) {
                $xmlDriver = $xmlDrivers->addChild("driver");
                $xmlDriver->addAttribute("name", $driver);
            }
        }
    }

    protected function generateApiVersions(\SimpleXMLElement $api, Matrix $matrix, $name) {
        $xmlVersions = $api->addChild("versions");
        foreach ($matrix->getApiVersions() as $apiVersion) {
            if ($apiVersion->getName() === $name) {
                $xmlVersion = $xmlVersions->addChild('version');
                $this->generateApiVersion($xmlVersion, $apiVersion, $matrix->getHints());
            }
        }
    }

    protected function generateApiVersion(\SimpleXMLElement $xmlVersion, ApiVersion $apiVersion, Hints $hints) {
        $xmlVersion->addAttribute("name", $apiVersion->getName());
        $xmlVersion->addAttribute("version", $apiVersion->getVersion());

        $shaderVersion = $xmlVersion->addChild("shader-version");
        $shaderVersion->addAttribute("name", $apiVersion->getGlslName());
        $shaderVersion->addAttribute("version", $apiVersion->getGlslVersion());

        $extensions = $xmlVersion->addChild("extensions");
        foreach ($apiVersion->getExtensions() as $glExt) {
            $xmlExt = $extensions->addChild("extension");
            $this->generateExtension($xmlExt, $glExt, $hints);
        }
    }

    protected function generateExtension(\SimpleXMLElement $xmlExt, OglExtension $glExt, Hints $hints) {
        $xmlExt->addAttribute("name", $glExt->getName());

        if ($this->urlCache) {
            if (preg_match("/(GLX?)_([^_]+)_([a-zA-Z0-9_]+)/", $glExt->getName(), $matches) === 1) {
                $openglUrl = \Mesamatrix::$config->getValue("opengl_links", "url_gl").urlencode($matches[2])."/";
                if ($matches[1] === "GLX") {
                    // Found a GLX_TYPE_Extension.
                    $openglUrl .= "GLX_";
                }

                $openglUrl .= urlencode($matches[2]."_".$matches[3]).".txt";

                if ($this->urlCache->isValid($openglUrl)) {
                    $linkNode = $xmlExt->addChild("link", $matches[0]);
                    $linkNode->addAttribute("href", $openglUrl);
                }
            }
        }

        $mesaStatus = $xmlExt->addChild("mesa");
        $mesaStatus->addAttribute("status", $glExt->getStatus());
        $mesaHintId = $glExt->getHintIdx();
        if ($mesaHintId !== -1) {
            $mesaStatus->addAttribute("hint", $hints->allHints[$mesaHintId]);
        }
        if ($commit = $glExt->getModifiedAt()) {
            $modified = $mesaStatus->addChild("modified");
            $modified->addChild("commit", $commit->getHash());
            $modified->addChild("date", $commit->getCommitterDate()->getTimestamp());
            $modified->addChild("author", $commit->getAuthor());
        }

        $supportedDrivers = $xmlExt->addChild("supported-drivers");
        foreach ($glExt->getSupportedDrivers() as $glDriver) {
            $driver = $supportedDrivers->addChild("driver");
            $driver->addAttribute("name", $glDriver->getName());
            $hintId = $glDriver->getHintIdx();
            if ($hintId !== -1) {
                $driver->addAttribute("hint", $hints->allHints[$hintId]);
            }
            if ($commit = $glDriver->getModifiedAt()) {
                $modified = $driver->addChild("modified");
                $modified->addChild("commit", $commit->getHash());
                $modified->addChild("date", $commit->getCommitterDate()->getTimestamp());
                $modified->addChild("author", $commit->getAuthor());
            }
        }

        $glSubExts = $glExt->getSubExtensions();
        if (!empty($glSubExts)) {
            $xmlSubExts = $xmlExt->addChild("subextensions");
            foreach ($glSubExts as $glSubExt) {
                $xmlSubExt = $xmlSubExts->addChild("subextension");
                $this->generateExtension($xmlSubExt, $glSubExt, $hints);
            }
        }
    }

    protected function loadUrlCache() {
        $this->urlCache = NULL;
        if(\Mesamatrix::$config->getValue("opengl_links", "enabled", false)) {
            // Load URL cache.
            $this->urlCache = new UrlCache();
            $this->urlCache->load();
        }
    }

    protected function saveUrlCache() {
        if ($this->urlCache) {
            $this->urlCache->save();
        }
    }
}
