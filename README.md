# PDF Curator

This is a PDF file merger that resolves and maintains internal page links, including cross-file internal links.  *PDF Curator* extends [FPDF & FPDI](https://github.com/Setasign/FPDI-FPDF) to preserve internal links because the current versions of those libraries do not.  *PDF Curator* also establishes a convention for creating internal links that work across the files to be merged.

# Usage

This package was mainly developed to enable functionality required by [*CLI Press*](https://github.com/blazing-threads/cli-press).  There is no thorough documentation available for how to use it.  What you see here is what you get.

```
<?php

$curator = new BlazingThreads\PdfCurator\Curator();

// files are merged in the order that you add them to the Curator

// add some files
foreach (glob('*.pdf') as $file) {
    $this->curator->addFile($file);
}

// you can also prepend files, so `../cover.pdf` will be the first page
$this->curator->prependFile('../cover.pdf');

// the `merge` method will give you back the merged file as a string.
// save it somewhere.
if (file_put_contents('output.pdf', $this->curator->merge())) {
    echo 'pdf files merged';
} else {
    echo 'oops! something broke';
}
```

# Special links

If you want one PDF to link to another one that is also getting merged, you have to set up specially formatted links.  These links must be URI-based links that reference the page `curator` with a hash to a named destination.  Examples would be

```
http://localhost/curator#some-named-destination

file://tmp/curator#some-named-destination
```

*PDF Curator* only looks for `/curator` and the remaining characters so the beginning of the URI link will be ignored and can be anything.  The destination must exist within one (and only one) of the documents to be merged.  If it exists in more than one, the link will be ignored as unresolvable.  Getting these URI links into your PDF is up to you, but they must exist as a Destination in the PDF resource stream.

Also, there is a special named destination format that will link to a specific page number in the final document.

```
http://localhost/curator#curated-page-3

file://tmp/curator#curated-page-3
```

The examples above would create a link to page three in the final document.