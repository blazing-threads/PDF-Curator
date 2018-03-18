<?php

/*
 * This file is part of PDF Curator.
 *
 * The MIT License (MIT)
 * Copyright © 2017
 *
 * Alex Carter, alex@blazeworx.com
 * Keith E. Freeman, pdf-curator@forsaken-threads.com
 *
 * For the full copyright and license information, please view the LICENSE
 * file that should have been distributed with this source code.
 */

namespace BlazingThreads\PdfCurator;


/**
 * Class PdfImporter
 *
 * This class was adapted from a gist by Andrey Tarantsov found at https://gist.github.com/andreyvit/2020422.
 * Below is the notice included with that gist:
 *       FPDI extension that preserves hyperlinks when copying PDF pages.
 *
 *       (c) 2012, Andrey Tarantsov <andrey@tarantsov.com>, provided under the MIT license.
 *
 *       Published at: https://gist.github.com/2020422
 *
 *       Note: the free version of FPDI requires unprotected PDFs conforming to spec version 1.4.
 *       I use qpdf (http://qpdf.sourceforge.net/) to preprocess PDFs before running through this
 *       code, invoking it like this:
 *
 *           qpdf --decrypt --stream-data=uncompress --force-version=1.4 src.pdf temp.pdf
 *
 *       then, after processing temp.pdf into out.pdf with FPDI, I run the following to re-establish
 *       protection:
 *
 *           qpdf --encrypt "" "" 40 --extract=n -- out.pdf final.pdf
 *
 * @package BlazingThreads\PdfCurator
 *
 * @property int $page
 * @property array $PageLinks
 *
 * @method Output($dest='', $name='', $isUTF8=false)
 */
class PdfImporter extends \FPDI
{
    /** @var array */
    protected $deferredDestinations = [];

    /** @var array */
    protected $duplicateDestinations = [];

    /**
     * @param int $pageNumber
     * @param string $boxName
     * @param null $groupXObject
     * @return int
     */
    public function importPage($pageNumber, $boxName = '/CropBox', $groupXObject = null)
    {
        $templateIndex = parent::importPage($pageNumber, $boxName);
        $template =& $this->_tpls[$templateIndex];

        /** @var PdfParser $parser */
        $parser =& $template['parser'];
        if ($parser->getPageOffset() === false) {
            $parser->setPageOffset($this->page);
        }

        // look for hyperlink annotations and store them in the template
        if (isset($parser->getPages()[$pageNumber - 1][1][1]['/Annots'])) {
            $annotations = $parser->getPages()[$pageNumber - 1][1][1]['/Annots'];
            $annotations = $this->resolve($parser, $annotations);
            $links = array();
            foreach ($annotations[1] as $annotation) {
                if ($annotation[0] == PdfParser::TYPE_DICTIONARY) {
                    // all links look like:  << /Type /Annot /Subtype /Link /Rect [...] ... >>
                    if ($annotation[1]['/Type'][1] == '/Annot' && $annotation[1]['/Subtype'][1] == '/Link') {
                        $rect = $annotation[1]['/Rect'];
                        if ($rect[0] != PdfParser::TYPE_ARRAY || count($rect[1]) != 4) {
                            continue;
                        } else {
                            $x = $rect[1][0][1];
                            $y = $rect[1][1][1];
                            $x2 = $rect[1][2][1];
                            $y2 = $rect[1][3][1];
                            $w = $x2 - $x;
                            $h = $y2 - $y;
                            $h = -$h;
                        }
                        if (isset($annotation[1]['/A'])) {
                            $A = $annotation[1]['/A'];
                            if ($A[0] == PdfParser::TYPE_DICTIONARY && isset($A[1]['/S'])) {
                                $S = $A[1]['/S'];
                                //  << /Type /Annot ... /A << /S /URI /URI ... >> >>
                                if ($S[1] == '/URI' && isset($A[1]['/URI'])) {
                                    $URI = $A[1]['/URI'];
                                    if (is_string($URI[1])) {
                                        $uri = str_replace("\\000", '', trim($URI[1]));
                                        // this directive is for cross-file internal link preservation
                                        if (preg_match('|^.+/curator#([^/]+)$|', $uri, $matches)) {
                                            // and this is a special page-link format
                                            if (strpos($matches[1], 'curated-page-') === 0) {
                                                $link = $this->AddLink();
                                                $this->SetLink($link, 0, substr(strrchr($matches[1], '-'), 1));
                                                $this->PageLinks[$this->page + 1][] = [$x, $y, $w, $h, $link];
                                            } else {
                                                $this->deferredDestinations[$this->currentFilename][] = [$x, $y, $w, $h, $matches[1], $this->page + 1, true];
                                            }
                                        } elseif (!empty($uri)) {
                                            $links[] = array($x, $y, $w, $h, $uri);
                                        }
                                    }
                                    //  << /Type /Annot ... /A << /S /GoTo /D [%d 0 R /Fit] >> >>
                                } else if ($S[1] == '/GoTo' && isset($A[1]['/D'])) {
                                    $D = $A[1]['/D'];
                                    if ($D[0] == PdfParser::TYPE_ARRAY && count($D[1]) > 0 && $D[1][0][0] == PdfParser::TYPE_OBJREF) {
                                        $targetPageNumber = $this->findPageNoForRef($parser, $D[1][0]);
                                        if ($targetPageNumber >= 0) {
                                            $links[] = array($x, $y, $w, $h, $targetPageNumber);
                                        }
                                    }
                                }
                            }
                        } elseif (isset($annotation[1]['/Dest'])) {
                            $Dest = $annotation[1]['/Dest'];
                            //  << /Type /Annot ... /Dest [42 0 R ...] >>
                            if ($Dest[0] == PdfParser::TYPE_ARRAY && $Dest[1][1][0] == PdfParser::TYPE_OBJREF) {
                                $targetPageNumber = $this->findPageNoForRef($parser, $Dest[1][1][0]);
                                if ($targetPageNumber >= 0) {
                                    $links[] = array($x, $y, $w, $h, $targetPageNumber);
                                }
                                //  << /Type /Annot ... /Dest (%s) >>
                            } elseif ($Dest[0] == PdfParser::TYPE_TOKEN) {
                                $targetPageNumber = $parser->getDestination($Dest[1]);
                                if (is_null($targetPageNumber)) {
                                    $this->deferredDestinations[$templateIndex][] = [$x, $y, $w, $h, $Dest[1], $this->page + 1, false];
                                } else {
                                    if($targetPageNumber != $this->page + 1) {
                                        $links[] = array($x, $y, $w, $h, $targetPageNumber);
                                    }
                                }
                            }
                        }
                    }
                }
            }
            $template['links'] = $links;
        }

        return $templateIndex;
    }

    /**
     *
     */
    public function resolveDeferredDestinations()
    {
        // we loop through everything looking for destinations only defined once in all pages
        // to save time for later steps, we keep track of everything found
        foreach ($this->deferredDestinations as $filename => $destinations) {
            foreach ($destinations as $destination) {
                // if we know about this destination it's because we found multiple results below
                // and we have no way to resolve which one to use
                if (in_array($destination[4], $this->duplicateDestinations)) {
                    continue;
                }
                $found = 0;
                $targetPageNumber = false;
                /** @var PdfParser $parser */
                foreach ($this->parsers as $parsedFilename => $parser) {
                    $result = $destination[6]
                        ? $parser->getCuratedDestination($destination[4])
                        : $parser->getDestination($destination[4]);
                    if (!is_null($result)) {
                        $targetPageNumber = $result;
                        $found++;
                        // once we find more than one match, we stop because we can't resolve the conflict
                        if ($found > 1) {
                            $this->duplicateDestinations[] = $destination[4];
                            break;
                        }
                    }
                }
                // we only found a single match, so the link has been successfully resolved
                if ($found === 1) {
                    $link = $this->AddLink();
                    $this->SetLink($link, 0, $targetPageNumber);
                    $destination[4] = $link;
                    $this->PageLinks[$destination[5]][] = $destination;
                }
            }
        }
    }

    /**
     * @param int $templateIndex
     * @param null $_x
     * @param null $_y
     * @param int $_w
     * @param int $_h
     * @param bool $adjustPageSize
     * @return array
     */
    public function useTemplate($templateIndex, $_x = null, $_y = null, $_w = 0, $_h = 0, $adjustPageSize = false)
    {
        $result = parent::useTemplate($templateIndex, $_x, $_y, $_w, $_h, $adjustPageSize);

        // apply links from the template
        $template =& $this->_tpls[$templateIndex];
        $template['page'] = $this->page;
        if (isset($template['links'])) {
            foreach ($template['links'] as $link) {
                // $link[4] is either a string (external URL) or an integer (page number)
                if (is_int($link[4])) {
                    $l = $this->AddLink();
                    $this->SetLink($l, 0, $link[4]);
                    $link[4] = $l;
                }
                $this->PageLinks[$this->page][] = $link;
            }
        }

        return $result;
    }

    /**
     * @param string $filename
     * @return PdfParser
     */
    protected function _getPdfParser($filename)
    {
        return new PdfParser($filename);
    }

    /**
     * @param PdfParser $parser
     * @param $pageRef
     * @return int|string
     */
    protected function findPageNoForRef(&$parser, $pageRef)
    {
        foreach ($parser->getPages() as $index => $pageSpec) {
            if ($pageSpec['obj'] == $pageRef[1] && $pageSpec['gen'] == $pageRef[2]) {
                return $index + 1;
            }
        }
        return -1;
    }

    /**
     * Default maxDepth prevents an infinite recursion on malformed PDFs
     *
     * @param PdfParser $parser
     * @param $objSpec
     * @param int $maxDepth
     * @return mixed
     */
    protected function resolve(&$parser, $objSpec, $maxDepth = 10)
    {
        if ($maxDepth == 0) {
            return $objSpec;
        }

        switch ($objSpec[0]) {
            case PdfParser::TYPE_OBJREF:
                $result = $parser->resolveObject($objSpec);
                return $this->resolve($parser, $result, $maxDepth - 1);

            case PdfParser::TYPE_OBJECT:
                return $this->resolve($parser, $objSpec[1], $maxDepth - 1);

            case PdfParser::TYPE_ARRAY:
                $result = array();
                foreach ($objSpec[1] as $item) {
                    $result[] = $this->resolve($parser, $item, $maxDepth - 1);
                }
                $objSpec[1] = $result;
                return $objSpec;

            case PdfParser::TYPE_DICTIONARY:
                $result = array();
                foreach ($objSpec[1] as $key => $item) {
                    $result[$key] = $this->resolve($parser, $item, $maxDepth-1);
                }
                $objSpec[1] = $result;
                return $objSpec;

            default:
                return $objSpec;
        }
    }
}