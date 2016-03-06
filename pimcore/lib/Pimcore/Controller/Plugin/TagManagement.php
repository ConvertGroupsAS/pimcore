<?php
/**
 * Pimcore
 *
 * This source file is subject to the GNU General Public License version 3 (GPLv3)
 * For the full copyright and license information, please view the LICENSE.md and gpl-3.0.txt
 * files that are distributed with this source code.
 *
 * @copyright  Copyright (c) 2009-2016 pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     GNU General Public License version 3 (GPLv3)
 */

namespace Pimcore\Controller\Plugin;

use Pimcore\Cache;
use Pimcore\Model\Tool;
use Pimcore\Model\Tool\Tag;
use Pimcore\Model\Site;

class TagManagement extends \Zend_Controller_Plugin_Abstract
{

    /**
     *
     */
    public function dispatchLoopShutdown()
    {
        if (!\Pimcore\Tool::isHtmlResponse($this->getResponse())) {
            return;
        }

        $list = new Tag\Config\Listing();
        $tags = $list->load();

        if (empty($tags)) {
            return;
        }

        $html = null;
        $body = $this->getResponse()->getBody();
        $requestParams = array_merge($_GET, $_POST);


        foreach ($tags as $tag) {
            $method = strtolower($tag->getHttpMethod());
            $pattern = $tag->getUrlPattern();
            $textPattern = $tag->getTextPattern();

            // site check
            if (Site::isSiteRequest() && $tag->getSiteId()) {
                if (Site::getCurrentSite()->getId() != $tag->getSiteId()) {
                    continue;
                }
            } elseif (!Site::isSiteRequest() && $tag->getSiteId()) {
                continue;
            }

            $requestPath = rtrim($this->getRequest()->getPathInfo(), "/");

            if (($method == strtolower($this->getRequest()->getMethod()) || empty($method)) &&
                (empty($pattern) || @preg_match($pattern, $requestPath)) &&
                (empty($textPattern) || strpos($body, $textPattern) !== false)
            ) {
                $paramsValid = true;
                foreach ($tag->getParams() as $param) {
                    if (!empty($param["name"])) {
                        if (!empty($param["value"])) {
                            if (!array_key_exists($param["name"], $requestParams) || $requestParams[$param["name"]] != $param["value"]) {
                                $paramsValid = false;
                            }
                        } else {
                            if (!array_key_exists($param["name"], $requestParams)) {
                                $paramsValid = false;
                            }
                        }
                    }
                }

                if (is_array($tag->getItems()) && $paramsValid) {
                    foreach ($tag->getItems() as $item) {
                        if (!empty($item["element"]) && !empty($item["code"]) && !empty($item["position"])) {

                            if(in_array($item["element"], ["body","head"])) {
                                // check if the code should be inserted using one of the presets
                                // because this can be done much faster than using a html parser
                                if($html) {
                                    // reset simple_html_dom if set
                                    $html->clear();
                                    unset($html);
                                    $html = null;
                                }

                                if($item["position"] == "end") {
                                    $regEx = "@</" . $item["element"] . ">@i";
                                    $body = preg_replace($regEx, "\n\n" . $item["code"] . "\n\n</" . $item["element"] . ">", $body, 1);
                                } else {
                                    $regEx = "/<" . $item["element"] . "([^a-zA-Z])?( [^>]+)?>/";
                                    $body = preg_replace($regEx, "<" . $item["element"] . "$1$2>\n\n" . $item["code"] . "\n\n" , $body, 1);
                                }

                            } else {
                                // use simple_html_dom
                                if (!$html) {
                                    include_once("simple_html_dom.php");
                                    $html = str_get_html($body);
                                }

                                if ($html) {
                                    $element = $html->find($item["element"], 0);
                                    if ($element) {
                                        if ($item["position"] == "end") {
                                            $element->innertext = $element->innertext . "\n\n" . $item["code"] . "\n\n";
                                        } else {
                                            // beginning
                                            $element->innertext = "\n\n" . $item["code"] . "\n\n" . $element->innertext;
                                        }

                                        // we havve to reinitialize the html object, otherwise it causes problems with nested child selectors
                                        $body = $html->save();

                                        $html->clear();
                                        unset($html);

                                        $html = null;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        if ($html && method_exists($html, "clear")) {
            $html->clear();
            unset($html);
        }

        $this->getResponse()->setBody($body);
    }
}
