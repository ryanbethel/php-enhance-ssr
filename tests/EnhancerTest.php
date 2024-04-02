<?php

require "vendor/autoload.php";
use PHPUnit\Framework\TestCase;
use Enhance\Enhancer;
use Enhance\Elements;

global $allElements;
$allElements = new Elements(__DIR__ . "/fixtures/templates");

// // function Elements
// require_once __DIR__ . "/fixtures/templates/my-context-child.php";
// require_once __DIR__ . "/fixtures/templates/my-pre.php";
// require_once __DIR__ . "/fixtures/templates/my-pre-page.php";

// // HTML Elements
// global $MyHeadingHTML;
// $MyHeadingHTML = loadFixtureHTML("my-heading.html");
// global $MultipleSlotsHTML;
// $MultipleSlotsHTML = loadFixtureHTML("multiple-slots.html");
// global $MyContentHTML;
// $MyContentHTML = loadFixtureHTML("my-content.html");
// global $MyParagraphHTML;
// $MyParagraphHTML = loadFixtureHTML("my-paragraph.html");

class EnhancerTest extends TestCase
{
    public function testEnhance()
    {
        global $allElements;
        $enhancer = new Enhancer([
            "elements" => $allElements,
            // "elements" => [
            //     "my-heading" => function ($state) use ($MyHeadingHTML) {
            //         return $MyHeadingHTML;
            //     },
            // ],
            "initialState" => ["message" => "Hello, World!"],
            "enhancedAttr" => false,
        ]);

        $htmlString =
            "<html><head><title>Test</title></head><body>Content</body></html>";
        $expectedString =
            "<html><head><title>Test</title></head><body>Content</body></html>";

        $this->assertSame(
            strip($expectedString),
            strip($enhancer->ssr($htmlString)),
            "The html doc matches."
        );

        $htmlString = "Fragment content";
        $expectedString = "<html><body><p>Fragment content</p></body></html>";

        $this->assertSame(
            strip($expectedString),
            strip($enhancer->ssr($htmlString)),
            "html, and body are added."
        );

        $htmlString =
            "<div><div><my-heading></my-heading></div></div><my-heading></my-heading>";
        $expectedString =
            "<html><body><div><div><my-heading><h1></h1></my-heading></div></div><my-heading><h1></h1></my-heading></body></html>";

        $this->assertSame(
            strip($expectedString),
            strip($enhancer->ssr($htmlString)),
            "Custom Element Expansion."
        );
    }
    public function testEmptySlot()
    {
        global $MyParagraphHTML;
        $enhancer = new Enhancer([
            "elements" => [
                "my-paragraph" => function ($state) use ($MyParagraphHTML) {
                    return $MyParagraphHTML;
                },
            ],
            "bodyContent" => true,
            "enhancedAttr" => false,
        ]);

        $htmlString = "<my-paragraph></my-paragraph>";
        $expectedString =
            "<my-paragraph><p><span slot=\"my-text\">My default text</span></p></my-paragraph>";

        $this->assertSame(
            strip($expectedString),
            strip($enhancer->ssr($htmlString)),
            "by gum, i do believe that it does expand that template with slotted default content"
        );
    }
    public function testTemplateExpansion()
    {
        global $MyParagraphHTML;
        $enhancer = new Enhancer([
            "elements" => [
                "my-paragraph" => function ($state) use ($MyParagraphHTML) {
                    return $MyParagraphHTML;
                },
            ],
            "bodyContent" => true,
            "enhancedAttr" => false,
        ]);

        $htmlString =
            "<my-paragraph><span slot=\"my-text\">I'm in a slot</span></my-paragraph>";
        $expectedString =
            "<my-paragraph><p><span slot=\"my-text\">I'm in a slot</span></p></my-paragraph>";

        $this->assertSame(
            strip($expectedString),
            strip($enhancer->ssr($htmlString)),
            "slotted content is added to the template"
        );
    }
    public function testAddEnhancedAttr()
    {
        global $MyParagraphHTML;
        $enhancer = new Enhancer([
            "elements" => [
                "my-paragraph" => function ($state) use ($MyParagraphHTML) {
                    return $MyParagraphHTML;
                },
            ],
            "bodyContent" => true,
            "enhancedAttr" => true,
        ]);

        $htmlString =
            "<my-paragraph><span slot=\"my-text\">I'm in a slot</span></my-paragraph>";
        $expectedString =
            "<my-paragraph enhanced=\"✨\"><p><span slot=\"my-text\">I'm in a slot</span></p></my-paragraph>";

        $this->assertSame(
            strip($expectedString),
            strip($enhancer->ssr($htmlString)),
            "Enhanced attribute is added to the template"
        );
    }
    public function testPassStateThroughLevels()
    {
        global $allElements;
        $enhancer = new Enhancer([
            "elements" => $allElements,
            "initialState" => ["items" => ["test"]],
            "bodyContent" => true,
            "enhancedAttr" => false,
        ]);

        $htmlString = "<my-pre-page items=\"\"></my-pre-page>";
        $expectedString = <<<HTMLCONTENT
            <my-pre-page items="">
                <my-pre items="">
                  <pre>test</pre>
                </my-pre>
              </my-pre-page>
HTMLCONTENT;

        $this->assertSame(
            strip($expectedString),
            strip($enhancer->ssr($htmlString)),
            "Enhanced attribute is added to the template"
        );
    }
}

function loadFixtureHTML($name)
{
    return file_get_contents(__DIR__ . "/fixtures/templates/$name");
}
function strip($str)
{
    return preg_replace('/\r?\n|\r|\s\s+/u', "", $str);
}
?>
