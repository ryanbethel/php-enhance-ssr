<?php

namespace Enhance;
use Exception;

class Elements
{
    public $functions = [];

    public function __construct($folderPath = null)
    {
        // Check if a valid folder path is provided
        if ($folderPath && is_dir($folderPath)) {
            // Handle PHP files
            $phpFunctionFiles = glob($folderPath . "/*.php");
            foreach ($phpFunctionFiles as $file) {
                require_once $file;
                $functionName = basename($file, ".php");
                $this->functions[$functionName] = $functionName; // Using function name as string
            }

            // Handle HTML files
            $htmlFunctionFiles = glob($folderPath . "/*.html");
            foreach ($htmlFunctionFiles as $file) {
                $functionName = basename($file, ".html");
                // Read the HTML file content and wrap it in a closure
                $htmlContent = file_get_contents($file);
                $this->functions[$functionName] = function ($state) use (
                    $htmlContent
                ) {
                    return $htmlContent;
                };
            }
        }
    }

    public function execute($functionName, ...$args)
    {
        if (isset($this->functions[$functionName])) {
            // Dynamically call the function/closure by name with arguments
            return call_user_func_array($this->functions[$functionName], $args);
        }
        throw new Exception("Function $functionName does not exist.");
    }
    public function exists($functionName)
    {
        return isset($this->functions[$functionName]);
    }
}
