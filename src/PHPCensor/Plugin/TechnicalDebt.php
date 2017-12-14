<?php

namespace PHPCensor\Plugin;

use PHPCensor;
use PHPCensor\Builder;
use PHPCensor\Model\Build;
use PHPCensor\Plugin;
use PHPCensor\ZeroConfigPluginInterface;

/**
* Technical Debt Plugin - Checks for existence of "TODO", "FIXME", etc.
*
* @author James Inman <james@jamesinman.co.uk>
*/
class TechnicalDebt extends Plugin implements ZeroConfigPluginInterface
{
  /**
  * @var array
  */
  protected $suffixes;

  /**
  * @var string
  */
  protected $directory;

  /**
  * @var int
  */
  protected $allowed_errors;

  /**
  * @var array - paths to ignore
  */
  protected $ignore;

  /**
  * @var array - terms to search for
  */
  protected $searches;

  public $errorPerFile                  = [];
  public $currentLineSize        = 0;
  public $lineNumber                  = 0;
  public $numberOfAnalysedFile      = 0;

  /**
  * @return string
  */
  public static function pluginName()
  {
    return 'technical_debt';
  }
  /**
  * Store the statu of the file :
  *   . : checked no errors
  *   X : checked with one or more errr
  *
  * @param  string $char
  */
  protected function buildLogString($char)
  {
    if (isset($this->errorPerFile[$this->lineNumber])){
      $this->errorPerFile[$this->lineNumber].= $char;
    }else{
      $this->errorPerFile[$this->lineNumber] = $char;
    }
    $this->currentLineSize++;
    $this->numberOfAnalysedFile++;
    if ($this->currentLineSize>59){
      $this->currentLineSize = 0;
      $this->lineNumber++;
    }
  }

  /**
  * Create a visual representation of file with Todo
  *  ...XX... 10/300 (10 %)
  *
  * @return string         The visual representation
  */
  protected function returnResult()
  {
    $string='';
    $nb=0;
    foreach ($this->errorPerFile as $id => $uneLigne){
      $nb+=strlen($uneLigne);
      $string.=str_pad($uneLigne,60, ' ', STR_PAD_RIGHT);;
      $string.=str_pad($nb,4, ' ', STR_PAD_LEFT);
      $string.="/".$this->numberOfAnalysedFile." (".floor($nb*100/$this->numberOfAnalysedFile)." %)\n";
    }
    $string.= "Checked $nb files\n";
    return $string;
  }

  /**
  * {@inheritdoc}
  */
  public function __construct(Builder $builder, Build $build, array $options = [])
  {
    parent::__construct($builder, $build, $options);

    $this->suffixes       = ['php'];
    $this->directory      = $this->builder->buildPath;
    $this->ignore         = $this->builder->ignore;
    $this->allowed_errors = 0;
    $this->searches       = ['TODO', 'FIXME', 'TO DO', 'FIX ME'];

    if (!empty($options['suffixes']) && is_array($options['suffixes'])) {
      $this->suffixes = $options['suffixes'];
    }

    if (!empty($options['searches']) && is_array($options['searches'])) {
      $this->searches = $options['searches'];
    }

    if (isset($options['zero_config']) && $options['zero_config']) {
      $this->allowed_errors = -1;
    }

    $this->setOptions($options);
  }

  /**
  * Handle this plugin's options.
  *
  * @param $options
  */
  protected function setOptions($options)
  {
    foreach (['directory', 'ignore', 'allowed_errors'] as $key) {
      if (array_key_exists($key, $options)) {
        $this->{$key} = $options[$key];
      }
    }
  }

  /**
  * Check if this plugin can be executed.
  *
  * @param string  $stage
  * @param Builder $builder
  * @param Build   $build
  *
  * @return boolean
  */
  public static function canExecute($stage, Builder $builder, Build $build)
  {
    if ($stage == Build::STAGE_TEST) {
      return true;
    }

    return false;
  }

  /**
  * Runs the plugin
  */
  public function execute()
  {
    $success    = true;
    $errorCount = $this->getErrorList();

    $this->builder->log($this->returnResult()."Found $errorCount instances of " . implode(', ', $this->searches));

    $this->build->storeMeta('technical_debt-warnings', $errorCount);

    if ($this->allowed_errors !== -1 && $errorCount > $this->allowed_errors) {
      $success = false;
    }

    return $success;
  }

  /**
  * Gets the number and list of errors returned from the search
  *
  * @return integer
  */
  protected function getErrorList()
  {
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory));

    $this->builder->logDebug("Ignored path: ".json_encode($this->ignore,true));
    $errorCount = 0;

    /** @var \SplFileInfo $file */
    foreach ($iterator as $file) {
      $filePath     = $file->getRealPath();
      $relativePath = (string)$file->getPathname();
      $extension    = $file->getExtension();

      $ignored = false;
      foreach ($this->suffixes as $suffix) {
        if ($suffix !== $extension) {
          $ignored = true;
          break;
        }
      }

      foreach ($this->ignore as $ignore) {
        if ('/' === $ignore{0}) {
          if (0 === strpos($filePath, $ignore)) {
            $ignored = true;
            break;
          }
        } else {
          $ignore = $this->directory.$ignore;
          if (0 === strpos($filePath, $ignore)) {
            $ignored = true;
            break;
          }
        }
      }

      if (!$ignored){
        $handle     = fopen($filePath, "r");
        $lineNumber = 1;
        while (false === feof($handle)) {
          $line = fgets($handle);
          $found=false;
          foreach ($this->searches as $search) {
            if ($technicalDeptLine = trim(strstr($line, $search))) {
              $fileName = str_replace($this->directory, '', $filePath);
              $this->build->reportError(
                $this->builder,
                'technical_debt',
                $technicalDeptLine,
                PHPCensor\Model\BuildError::SEVERITY_LOW,
                $fileName,
                $lineNumber
              );
              $found=true;
              $errorCount++;
            }
          }
          $lineNumber++;
        }
        fclose ($handle);
        if ($found === true){
          $this->buildLogString('X');
        }
        else{
          $this->buildLogString('.');
        }
      }
    }

    return $errorCount;
  }
}
