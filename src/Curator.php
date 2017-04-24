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

class Curator
{
    /** @var array */
    protected $filePaths = [];

    /** @var array */
    protected $order = [];

    /** @var PdfImporter */
    protected $pdfImporter;

    /**
     * Curator constructor.
     */
    public function __construct()
    {
        $this->pdfImporter = new PdfImporter();
    }

    /**
     * @param $filePath
     * @param bool $prepend
     * @return $this
     * @throws \Exception
     */
    public function addFile($filePath, $prepend = false)
    {
        if (!is_string($filePath) || !file_exists($filePath)) {
            throw new \Exception('Expected string path for existing file. Received: ' . @(string) $filePath);
        }

        $this->filePaths[$filePath] = $this->pdfImporter->setSourceFile($filePath);

        if ($prepend) {
            array_unshift($this->order, $filePath);
        } else {
            $this->order[] = $filePath;
        }

        return $this;
    }

    /**
     * @param $filePath
     * @return $this
     */
    public function prependFile($filePath)
    {
        return $this->addFile($filePath, true);
    }

    /**
     * @param $filePath
     * @return int
     * @throws \Exception
     */
    public function getPageCount($filePath)
    {
        if (!is_string($filePath)) {
            throw new \Exception('Expected string path. Received: ' . @(string) $filePath);
        }

        if (!key_exists($filePath, $this->filePaths)) {
            $this->addFile($filePath);
        }

        return $this->filePaths[$filePath];
    }

    /**
     * @return mixed
     * @throws \Exception
     */
    public function merge()
    {
        if (empty($this->filePaths)) {
            throw new \Exception('No files to merge.');
        }

        foreach ($this->order as $filePath) {
            $this->pdfImporter->setSourceFile($filePath);
            foreach (range(1, $this->filePaths[$filePath]) as $page) {
                $template = $this->pdfImporter->importPage($page);
                $size = $this->pdfImporter->getTemplateSize($template);
                $orientation = ($size['w'] > $size['h']) ? 'L' : 'P';
                $this->pdfImporter->AddPage($orientation, array($size['w'], $size['h']));
                $this->pdfImporter->useTemplate($template);
            }
        }

        $this->pdfImporter->resolveDeferredDestinations();

        $output = $this->pdfImporter->Output('S');

        $this->pdfImporter->cleanUp();
        $this->filePaths = [];

        return $output;
    }
}