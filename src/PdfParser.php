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

class PdfParser extends \fpdi_pdf_parser
{
    protected $pageOffset = false;

    /**
     * @param $destination
     * @return null
     */
    public function getCuratedDestination($destination)
    {
        if (!$this->hasDestinations()) {
            return null;
        }

        foreach ($this->getDestinations()[1][1] as $dest => $objSpec) {
            if (preg_match("|^/file#3a#2f#2f#2f.+#23$destination|", $dest)) {
                return $this->resolveObject($objSpec)[1][1][0][1] + 1 + $this->getPageOffset();
            }
        }

        return null;
    }

    /**
     * @param $destination
     * @return int|null
     */
    public function getDestination($destination)
    {
        if (!$this->hasDestinations()) {
            return null;
        }

        $destinations = $this->getDestinations();

        if (!isset($destinations[1][1][$destination])) {
            return null;
        }

        // resolves to an /XYZ reference but we just need the page number
        // which is zero indexed so we add one, plus we need to account for the offset of the current page
        return $this->resolveObject($destinations[1][1][$destination])[1][1][0][1] + 1 + $this->getPageOffset();
    }

    /**
     * @return array
     */
    public function getDestinations()
    {
        return $this->hasDestinations()
            ? $this->resolveObject($this->_root[1][1]['/Dests'])
            : [];
    }

    /**
     * @return bool
     */
    public function hasDestinations()
    {
        return isset($this->_root[1][1]['/Dests']);
    }

    /**
     * @return array
     */
    public function getPages()
    {
        return $this->_pages;
    }

    /**
     * @return mixed
     */
    public function getPageOffset()
    {
        return $this->pageOffset;
    }

    /**
     * @param mixed $pageOffset
     */
    public function setPageOffset($pageOffset)
    {
        $this->pageOffset = $pageOffset;
    }
}