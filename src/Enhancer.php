<?php

namespace Enhance;

use Enhance\Elements;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMNodeList;
use DOMXPath;
use DOMText;

class Enhancer
{
    private $options;
    private $elements;
    private $store;

    public function __construct($options = [])
    {
        // Default options setup
        $defaultOptions = [
            "elements" => new Elements(), // Initialize with an empty Elements instance
            "initialState" => [],
            "scriptTransforms" => [],
            "styleTransforms" => [],
            "uuidFunction" => function () {
                return $this->generateRandomString(15);
            },
            "bodyContent" => false,
            "enhancedAttr" => true,
        ];

        // If 'elements' is provided in options and is an instance of Elements, use it directly
        if (
            isset($options["elements"]) &&
            $options["elements"] instanceof Elements
        ) {
            $defaultOptions["elements"] = $options["elements"];
        }
        // Merge user options with default options
        $this->options = array_merge($defaultOptions, $options);
        // Make sure 'elements' is an instance of Elements, not an array
        if (!($this->options["elements"] instanceof Elements)) {
            // Fallback or throw an exception
            throw new \Exception(
                "The 'elements' option must be an instance of Elements."
            );
        }
        $this->elements = $this->options["elements"];
        $this->store = $this->options["initialState"];
    }

    public function ssr($htmlString)
    {
        $doc = new DOMDocument();
        // if bodyContent is true, don't add html and body tags
        @$doc->loadHTML($htmlString, LIBXML_HTML_NODEFDTD);

        $htmlElement = $doc->getElementsByTagName("html")->item(0);
        $bodyElement = $htmlElement
            ? $doc->getElementsByTagName("body")->item(0)
            : null;
        $headElement = $htmlElement
            ? $doc->getElementsByTagName("head")->item(0)
            : null;

        if ($bodyElement) {
            $this->processCustomElements($bodyElement);
            if ($this->options["bodyContent"]) {
                $bodyContents = "";
                foreach ($bodyElement->childNodes as $childNode) {
                    $bodyContents .= $doc->saveHTML($childNode);
                }
                return $bodyContents;
            } else {
                return $doc->saveHTML();
            }
        }

        return $doc->saveHTML();
    }

    private function processCustomElements(&$node)
    {
        $collectedStyles = [];
        $collectedScripts = [];
        $collectedLinks = [];
        $context = [];

        $this->walk($node, function ($child) use (
            &$collectedStyles,
            &$collectedScripts,
            &$collectedLinks,
            &$context
        ) {
            if ($this->isCustomElement($child->tagName)) {
                if ($this->elements->exists($child->tagName)) {
                    $expandedTemplate = $this->expandTemplate([
                        "node" => $child,
                        "elements" => $this->elements,
                        "state" => [
                            "context" => $context,
                            "instanceID" => $this->options["uuidFunction"](),
                            "store" => $this->store,
                        ],
                        "styleTransforms" => $this->options["styleTransforms"],
                        "scriptTransforms" =>
                            $this->options["scriptTransforms"],
                    ]);

                    if ($this->options["enhancedAttr"] === true) {
                        $child->setAttribute("enhanced", "✨");
                    }

                    // Assuming $expandedTemplate contains arrays of DOMNodes or similar for scripts, styles, links
                    $collectedScripts = array_merge(
                        $collectedScripts,
                        $expandedTemplate["scripts"]
                    );
                    $collectedStyles = array_merge(
                        $collectedStyles,
                        $expandedTemplate["styles"]
                    );
                    $collectedLinks = array_merge(
                        $collectedLinks,
                        $expandedTemplate["links"]
                    );

                    $this->fillSlots($expandedTemplate["frag"], $child);
                    $importedFrag = $child->ownerDocument->importNode(
                        $expandedTemplate["frag"],
                        true
                    );
                    // $child->appendChild($importedFrag);
                    return $child;
                }
            }
        });

        return [
            "collectedStyles" => $collectedStyles,
            "collectedScripts" => $collectedScripts,
            "collectedLinks" => $collectedLinks,
        ];
    }

    function fillSlots($template, $node)
    {
        $slots = $this->findSlots($template); // Assuming this returns a DOMNodeList of slot elements
        $inserts = $this->findInserts($node); // Assuming this returns an array of insert elements

        $usedSlots = [];
        $usedInserts = [];
        $unnamedSlots = [];

        foreach ($slots as $slot) {
            $hasSlotName = false;
            $slotName = $slot->getAttribute("name");

            if ($slotName) {
                $hasSlotName = true;
                foreach ($inserts as $insert) {
                    $insertSlot = $insert->getAttribute("slot");
                    if ($insertSlot === $slotName) {
                        if ($slot->parentNode) {
                            $importedInsert = $slot->ownerDocument->importNode(
                                $insert,
                                true
                            );
                            $slot->parentNode->replaceChild(
                                $importedInsert,
                                $slot
                            );
                        }
                        $usedSlots[] = $slot;
                        $usedInserts[] = $insert;
                    }
                }
            }
            if (!$hasSlotName) {
                $unnamedSlots[] = $slot;
            }
        }

        foreach ($unnamedSlots as $slot) {
            $unnamedChildren = [];
            foreach ($node->childNodes as $child) {
                if (
                    // $child instanceof DOMElement &&
                    !in_array($child, $usedInserts)
                ) {
                    $unnamedChildren[] = $child;
                }
            }

            $slotDocument = $slot->ownerDocument;
            $slotParent = $slot->parentNode;
            foreach ($unnamedChildren as $child) {
                $importedNode = $slotDocument->importNode($child, true);
                $slotParent->insertBefore($importedNode, $slot);
            }
            $slotParent->removeChild($slot);
        }
        $unusedSlots = [];
        foreach ($slots as $slot) {
            // Check if the current $slot is in the $usedSlots array
            $isUsed = false;
            foreach ($usedSlots as $usedSlot) {
                if ($slot->isSameNode($usedSlot)) {
                    $isUsed = true;
                    break;
                }
            }
            if (!$isUsed) {
                $unusedSlots[] = $slot;
            }
        }
        $this->replaceSlots($template, $unusedSlots);
        while ($node->firstChild) {
            $node->removeChild($node->firstChild);
        }

        // Assuming $anotherDoc is another DOMDocument or the same document,
        // and $template is the node whose children you want to copy to $node
        foreach ($template->childNodes as $childNode) {
            $importedNode = $node->ownerDocument->importNode($childNode, true); // Import each child
            $node->appendChild($importedNode); // Append imported child to the target node
        }
    }

    public function findSlots(DOMNode $node)
    {
        $xpath = new DOMXPath($node->ownerDocument);
        $slots = $xpath->query(".//slot", $node);

        return $slots;
    }
    public function findInserts(DOMNode $node)
    {
        $inserts = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $child->hasAttribute("slot")) {
                $inserts[] = $child;
            }
        }
        return $inserts;
    }

    function replaceSlots(DOMNode $node, $slots)
    {
        foreach ($slots as $slot) {
            $value = $slot->getAttribute("name");
            $asTag = $slot->hasAttribute("as")
                ? $slot->getAttribute("as")
                : "span";

            // Filter slot's child nodes to exclude text nodes starting with '#'
            $slotChildren = [];
            foreach ($slot->childNodes as $child) {
                if (!($child instanceof DOMText)) {
                    $slotChildren[] = $child;
                }
            }

            if ($value) {
                $doc = $slot->ownerDocument;
                // Prepare a new element or use span as default
                $wrapper = $doc->createElement($asTag);
                $wrapper->setAttribute("slot", $value);

                // If there are no slot children or multiple children, wrap them
                if (count($slotChildren) === 0 || count($slotChildren) > 1) {
                    foreach ($slot->childNodes as $child) {
                        $wrapper->appendChild($child->cloneNode(true));
                    }
                } elseif (count($slotChildren) === 1) {
                    // If there's exactly one child, move it outside and remove the slot
                    $slot->parentNode->insertBefore(
                        $slotChildren[0]->cloneNode(true),
                        $slot
                    );
                }

                // Replace slot with wrapper or move child outside
                if ($wrapper->hasChildNodes()) {
                    $slot->parentNode->replaceChild($wrapper, $slot);
                } else {
                    $slot->parentNode->removeChild($slot);
                }
            }
        }

        return $node;
    }

    public function expandTemplate($params)
    {
        $node = $params["node"];
        $elements = $params["elements"];
        $state = $params["state"];
        $styleTransforms = $params["styleTransforms"];
        $scriptTransforms = $params["scriptTransforms"];
        $tagName = $node->tagName;
        $frag = $this->renderTemplate([
            "name" => $tagName,
            "elements" => $elements,
            // Assuming attrs to be an associative array representation of attributes
            "attrs" => $this->getNodeAttributes($node),
            "state" => $state,
        ]);

        $styles = [];
        $scripts = [];
        $links = [];

        foreach ($frag->childNodes as $childNode) {
            // Removing a child while iterating directly can disrupt the iteration,
            // so it's safer in PHP to collect nodes to remove and process them afterwards.

            if ($childNode->nodeName === "script") {
                $transformedScript = $this->applyScriptTransforms([
                    "node" => $childNode,
                    "scriptTransforms" => $scriptTransforms,
                    "tagName" => $tagName,
                ]);
                if ($transformedScript) {
                    $scripts[] = $transformedScript;
                }
            } elseif ($childNode->nodeName === "style") {
                $transformedStyle = $this->applyStyleTransforms([
                    "node" => $childNode,
                    "styleTransforms" => $styleTransforms,
                    "tagName" => $tagName,
                    "context" => "markup",
                ]);
                if ($transformedStyle) {
                    $styles[] = $transformedStyle;
                }
            } elseif ($childNode->nodeName === "link") {
                $links[] = $childNode;
            }
        }

        // Assuming the removal of processed nodes from frag is required
        // It's done after the iteration to avoid altering the NodeList during iteration
        $this->removeNodes($frag, array_merge($scripts, $styles, $links));

        return [
            "frag" => $frag,
            "styles" => $styles,
            "scripts" => $scripts,
            "links" => $links,
        ];
    }

    private static function removeNodes($frag, $nodesToRemove)
    {
        foreach ($nodesToRemove as $node) {
            if ($node->parentNode) {
                $node->parentNode->removeChild($node);
            }
        }
    }

    function applyScriptTransforms($params)
    {
        $node = $params["node"];
        $scriptTransforms = $params["scriptTransforms"];
        $tagName = $params["tagName"];

        $attrs = $this->getNodeAttributes($node);

        if ($node->hasChildNodes()) {
            $raw = $node->firstChild->nodeValue;
            $out = $raw;
            foreach ($scriptTransforms as $transform) {
                $out = $transform([
                    "attrs" => $attrs,
                    "raw" => $out,
                    "tagName" => $tagName,
                ]);
            }
            if (!empty($out)) {
                $node->firstChild->nodeValue = $out;
            }
        }
        return $node;
    }

    function applyStyleTransforms($params)
    {
        $node = $params["node"];
        $styleTransforms = $params["styleTransforms"];
        $tagName = $params["tagName"];
        $context = $params["context"] ?? "";

        $attrs = $this->getNodeAttributes($node);

        if ($node->hasChildNodes()) {
            $raw = $node->firstChild->nodeValue;
            $out = $raw;
            foreach ($styleTransforms as $transform) {
                $out = $transform([
                    "attrs" => $attrs,
                    "raw" => $out,
                    "tagName" => $tagName,
                    "context" => $context,
                ]);
            }
            if (!empty($out)) {
                $node->firstChild->nodeValue = $out;
            }
        }
        return $node;
    }

    private static function appendNodes($target, $nodes)
    {
        foreach ($nodes as $node) {
            // Assuming $node is a DOMNode that might belong to another document
            $importedNode = $target->ownerDocument->importNode($node, true);
            $target->appendChild($importedNode);
        }
    }

    private static function getNodeAttributes($node)
    {
        $attrs = [];
        if ($node->hasAttributes()) {
            foreach ($node->attributes as $attr) {
                $attrs[$attr->nodeName] = $attr->nodeValue;
            }
        }
        return $attrs;
    }

    public function renderTemplate($params)
    {
        $name = $params["name"];
        $elements = $params["elements"];
        $attrs = $params["attrs"] ?? [];
        $state = $params["state"] ?? [];

        // $attrs = $this->attrsToState($attrs);
        $state["attrs"] = $attrs;
        $doc = new DOMDocument();
        $rendered = $elements->execute($name, $state);
        $fragment = $doc->createDocumentFragment();
        $fragment->appendXML($rendered);
        return $fragment;
    }

    private function attrsToState($attrs = [], $obj = [])
    {
        if (!is_array($attrs)) {
            // Optionally, log an error or throw an exception
            error_log(
                "attrsToState expects the first parameter to be an array."
            );
            return $obj;
        }

        foreach ($attrs as $attr) {
            if (!isset($attr["name"]) || !isset($attr["value"])) {
                // Optionally, log an error or skip the malformed attribute
                continue;
            }
            $obj[$attr["name"]] = $this->decode($attr["value"]);
        }

        return $obj;
    }

    private function walk($node, $callback)
    {
        if ($callback($node) === false) {
            return false;
        }
        foreach ($node->childNodes as $childNode) {
            if ($this->walk($childNode, $callback) === false) {
                return false;
            }
        }
    }

    public static function generateRandomString($length = 10)
    {
        return bin2hex(random_bytes($length / 2));
    }

    private $map = [];
    private $place = 0;

    public function encode($value)
    {
        if (is_string($value) || is_numeric($value)) {
            return $value;
        } else {
            $id = "__b_" . $this->place++;
            $this->map[$id] = $value;
            return $id;
        }
    }

    public function decode($value)
    {
        return strpos($value, "__b_") === 0 ? $this->map[$value] : $value;
    }

    public static function isCustomElement($tagName)
    {
        //TODO: this is a simplification of the tag naming spec. PENChars spec needs to be added here.
        $regex = '/^[a-z][a-z0-9_.\-]*\-[a-z0-9_.\-]*$/u';
        $reservedTags = [
            "annotation-xml",
            "color-profile",
            "font-face",
            "font-face-src",
            "font-face-uri",
            "font-face-format",
            "font-face-name",
            "missing-glyph",
        ];

        if (in_array($tagName, $reservedTags)) {
            return false;
        }

        return preg_match($regex, $tagName) === 1;
    }
}
