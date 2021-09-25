<?php

use MediaWiki\MediaWikiServices;
use Wikimedia\Mime\MimeMap;

class AttachFiles {
    private static $fileTypes = [];

    private static function initializeFileTypes() {
        if (empty(self::$fileTypes)) {
            self::$fileTypes["attachfiles-pdf-document"] = [];
            self::$fileTypes["attachfiles-text-document"] = [];
            self::$fileTypes["attachfiles-presentation"] = [];
            self::$fileTypes["attachfiles-spreadsheet"] = [];
            self::$fileTypes["attachfiles-document"] = [];
            foreach (MimeMap::MEDIA_TYPES[MEDIATYPE_OFFICE] as $mimeType) {
                if ($mimeType === "application/pdf" || $mimeType === "application/acrobat" || strpos($mimeType, "djvu") !== false) {
                    self::$fileTypes["attachfiles-pdf-document"][] = $mimeType;
                } else if (strpos($mimeType, "word") !== false || strpos($mimeType, "text") !== false) {
                    self::$fileTypes["attachfiles-text-document"][] = $mimeType;
                } else if (strpos($mimeType, "powerpoint") !== false || strpos($mimeType, "presentation") !== false) {
                    self::$fileTypes["attachfiles-presentation"][] = $mimeType;
                } else if (strpos($mimeType, "excel") !== false || strpos($mimeType, "spreadsheet") !== false) {
                    self::$fileTypes["attachfiles-spreadsheet"][] = $mimeType;
                } else {
                    self::$fileTypes["attachfiles-document"][] = $mimeType;
                }
            }
            self::$fileTypes["attachfiles-text-document"][] = "text/plain";
            self::$fileTypes["attachfiles-text-document"][] = "text";
            self::$fileTypes["attachfiles-spreadsheet"][] = "text/csv";
            self::$fileTypes["attachfiles-spreadsheet"][] = "text/tab-separated-values";
            self::$fileTypes["attachfiles-image"] = array_merge(MimeMap::MEDIA_TYPES[MEDIATYPE_BITMAP], MimeMap::MEDIA_TYPES[MEDIATYPE_DRAWING]);
            self::$fileTypes["attachfiles-audio-file"] = MimeMap::MEDIA_TYPES[MEDIATYPE_AUDIO];
            // MediaWiki detects ogg/opus files always as application/ogg, regardless of whether they were actually audio or video.
            // As a result, we made the decision to classify all ogg/opus files as audio, unfortunately.
            self::$fileTypes["attachfiles-audio-file"][] = "application/ogg";
            self::$fileTypes["attachfiles-video-file"] = MimeMap::MEDIA_TYPES[MEDIATYPE_VIDEO];
            self::$fileTypes["attachfiles-archive-file"] = MimeMap::MEDIA_TYPES[MEDIATYPE_ARCHIVE];
        }
    }

    private static function getFileType($file) {
        // Initialize file type mapping.
        self::initializeFileTypes();

        $mimeType = $file->getMimeType();
        // TODO: should we enable this?
        // $mimeType = MimeMap::MIME_TYPE_ALIASES[$mimeType] ?? $mimeType;
        foreach (self::$fileTypes as $fileType => $mimeTypes) {
            if (in_array($mimeType, $mimeTypes)) {
                return $fileType;
            }
        }

        return "attachfiles-file";
    }

    private static function formatBytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    private static function generateJavaScript($context, $id, $namespace, $attachedFiles) {
        // MediaWiki uses underscores instead of spaces for links.
        $attachedFilesLink = str_replace(" ", "_", $attachedFiles);
        $maxUploadSize = min(UploadBase::getMaxUploadSize("file"), UploadBase::getMaxPhpUploadSize());
        $maxUploadSizeNice = self::formatBytes($maxUploadSize);
        $deleteConfirm = $context->msg("attachfiles-delete-confirm");
        $deleteReason = $context->msg("attachfiles-delete-reason");
        // Custom message here because the default MediaWiki one refers to scary groups.
        $deletePermissionError = $context->msg("attachfiles-delete-permission-error");
        $uploadComment = $context->msg("attachfiles-upload-comment");
        $uploadFileSizeError = $context->msg("attachfiles-upload-file-size-error");
        $uploadInvalidNameError = $context->msg("attachfiles-upload-invalid-name-error");
        $uploadExistsError = $context->msg("attachfiles-upload-exists-error");
        // Custom message here because the default MediaWiki one refers to scary groups.
        $uploadPermissionError = $context->msg("attachfiles-upload-permission-error");
        return <<<EOD
    function deleteFile(articleID, name) {
        if (!confirm(`$deleteConfirm`)) {
            return;
        }

        (new mw.Api()).postWithEditToken({
            formatversion: 2,
            action: "delete",
            pageid: articleID,
            reason: `$deleteReason`,
            uselang: mw.config.get("wgUserLanguage"),
            errorformat: "plaintext"
        }).then(function(result) {
            window.location.reload();
        }).catch(function(code, details) {
            if (code === "http") {
                alert("HTTP error: " + details.exception);
            } else if (code === "permissiondenied") {
                alert(`$deletePermissionError`);
            } else {
                alert(details.errors[0].text);
            }
        });
    }

    function uploadFile() {
        var form = document.getElementById("attachfiles_form");
        var uploadButton = document.getElementById("attachfiles_upload");
        uploadButton.disabled = true;

        var maxUploadSize = $maxUploadSize;
        var file = document.getElementById("attachfiles_file").files[0];
/*        if (file.size > maxUploadSize) {
            maxUploadSize = "$maxUploadSizeNice";
            alert(`$uploadFileSizeError`);
            uploadButton.disabled = false;
            return;
        } */

        var fileExtension = file.name.substring(file.name.lastIndexOf("."));
        var displayName = document.getElementById("attachfiles_name").value;
        var name = $id + "_" + displayName + fileExtension;

        // First request: we don't ignore warnings now because we want to handle some of them.
        (new mw.Api()).postWithEditToken({
            formatversion: 2,
            action: "upload",
            file: file,
            filename: name,
            comment: `$uploadComment`,
            uselang: mw.config.get("wgUserLanguage"),
            errorformat: "plaintext",
            attachfiles_id: $id,
            attachfiles_displayname: displayName,
            attachfiles_namespace: $namespace
        }, {
            contentType: "multipart/form-data",
            timeout: 0
        }).then(function(result) {
            // This will probably never happen.
            if (!result.upload.warnings) {
                form.reset();
                window.location.reload();
                return;
            }

            if (result.upload.warnings.badfilename) {
                // We don't allow bad file names.
                alert(`$uploadInvalidNameError`);
                uploadButton.disabled = false;
            } else if (result.upload.warnings.exists) {
                // We don't allow duplicate file names because this would overwrite the existing file.
                alert(`$uploadExistsError`);
                uploadButton.disabled = false;
            } else {
                fileKeyExtension = result.upload.filekey.substring(result.upload.filekey.lastIndexOf("."));
                // Server modified the file.
                if (fileKeyExtension !== fileExtension) {
                    name = $id + "_" + displayName + fileKeyExtension;
                }

                // Other warnings can be ignored at this time.
                (new mw.Api()).postWithEditToken({
                    formatversion: 2,
                    action: "upload",
                    filekey: result.upload.filekey,
                    filename: name,
                    comment: `$uploadComment`,
                    ignorewarnings: true,
                    uselang: mw.config.get("wgUserLanguage"),
                    errorformat: "plaintext",
                    attachfiles_id: $id,
                    attachfiles_displayname: displayName,
                    attachfiles_namespace: $namespace
                }, {
                    contentType: "multipart/form-data",
                    timeout: 0
                }).then(function(result) {
                    form.reset();
                    window.location.reload();
                }).catch(function(code, details) {
                    uploadButton.disabled = false;
                    if (code === "http") {
                        alert("HTTP error: " + details.exception);
                    } else if (code === "permissiondenied") {
                        alert(`$uploadPermissionError`);
                    } else {
                        alert(details.errors[0].text);
                    }
                });
            }
        }).catch(function(code, details) {
            uploadButton.disabled = false;
            if (code === "http") {
                alert("HTTP error: " + details.exception);
            } else if (code === "permissiondenied") {
                alert(`$uploadPermissionError`);
            } else {
                alert(details.errors[0].text);
            }
        });
    }

    var toc = document.getElementById("toc");
    if (toc) {
        var ul = toc.getElementsByTagName("ul")[0];
        var tocnumber = ul.getElementsByClassName("toclevel-1").length + 1;
        var li = document.createElement("li");
        li.setAttribute("class", `toclevel-1 tocsection-\${tocnumber}`);
        li.innerHTML = `<a href="#$attachedFilesLink"><span class="tocnumber">\${tocnumber}</span> <span class="toctext">$attachedFiles</span></a>`;
        ul.appendChild(li);
    }
EOD;
    }

    public static function onLoadExtensionSchemaUpdates($updater) {
        $dir = __DIR__ . "/sql";
        $updater->addExtensionTable("attachfiles_attached", "$dir/attached.sql");
        return true;
    }

    public static function onBeforePageDisplay($out, $skin) {
        global $wgContentNamespaces;
        global $wgAFIgnoredPages;

        $title = $out->getTitle();
        $id = $title->getArticleID();
        $namespace = $title->getNamespace();
        $context = RequestContext::getMain();
        $action = Action::getActionName($context);
        $user = $context->getUser();
        $canDelete = $user->isAllowedAny("delete");
        $canUpload = $user->isAllowedAny("upload");
        if ($id > 0 && !in_array($title->getPrefixedText(), $wgAFIgnoredPages ?? []) && in_array($namespace, $wgContentNamespaces) && $action === "view") {
            $attachedFiles = $context->msg("attachfiles-attached-files");
            $out->addInlineScript(self::generateJavaScript($context, $id, $namespace, $attachedFiles));
            $out->addWikiTextAsInterface("== $attachedFiles ==");

            $services = MediaWikiServices::getInstance();
            $lb = $services->getDBLoadBalancer();
            $dbr = $lb->getConnectionRef(DB_REPLICA);

            $res = $dbr->select("attachfiles_attached", ["filename", "displayname"], ["pageid" => $id], __METHOD__, []);
            if ($res->numRows() > 0) {
                $table = "<table class=\"wikitable sortable\">\n";
                $table .= "<tbody>\n";
                $table .= "<tr>";
                $table .= "<th>" . $context->msg("attachfiles-file") . "</th>";
                $table .= "<th>" . $context->msg("attachfiles-file-type") . "</th>";
                $table .= "<th>" . $context->msg("attachfiles-upload-date") . "</th>";
                $table .= "<th>" . $context->msg("attachfiles-uploader") . "</th>";
                if ($canDelete) {
                    $table .= "<th class=\"unsortable\">" . $context->msg("attachfiles-delete") . "</th>";
                }
                $table .= "</tr>\n";

                $tableRows = [];
                foreach ($res as $row) {
                    $fileName = $row->filename;
                    $downloadName = substr($fileName, strpos($fileName, "_") + 1);
                    $file = $services->getRepoGroup()->findFile($fileName);
                    $fileType = self::getFileType($file);
                    $url = htmlentities($file->getUrl());
                    $displayName = htmlentities($row->displayname);
                    $timestamp = $file->getTimestamp();
                    $tableRow = "<tr>";
                    $tableRow .= "<td><img src=\"extensions/AttachFiles/icons/$fileType.png\" style=\"padding-right: 0.3em\"><a href=\"$url\" download=\"$downloadName\">$displayName</a></td>";
                    $tableRow .= "<td>" . $context->msg($fileType) . "</td>";
                    $tableRow .= "<td data-sort-value=\"$timestamp\">" . $context->getLanguage()->date($timestamp, true) . "</td>";
                    $tableRow .= "<td>" . htmlentities($file->getUser()) . "</td>";
                    if ($canDelete) {
                        $articleID = $file->getTitle()->getArticleID();
                        $tableRow .= "<td><a href=\"javascript:deleteFile($articleID, `$displayName`);\">" . $context->msg("attachfiles-delete") . "</a></td>";
                    }
                    $tableRow .= "</tr>\n";
                    $tableRows[$tableRow] = $timestamp;
                }

                // Make sure the rows in the table are sorted by ascending timestamp.
                arsort($tableRows);
                foreach ($tableRows as $row => $timestamp) {
                    $table .= $row;
                }

                $table .= "</tbody>\n";
                $table .= "</table>\n";
                $out->addHTML($table);
            } else {
                $out->addWikiMsg("attachfiles-no-attached-files");
            }

            if ($canUpload) {
                $fileNamePlaceholder = $context->msg("attachfiles-file-name-placeholder");
                $uploadSubmitText = $context->msg("attachfiles-upload");
                $uploadForm = "<form id=\"attachfiles_form\" onsubmit=\"uploadFile(); return false;\">";
                $uploadForm .= "<p style=\"line-height: 2.3em\">";
                $uploadForm .= "<input type=\"file\" id=\"attachfiles_file\" required style=\"width: 25.5em\">";
                $uploadForm .= "<br>";
                $uploadForm .= "<input type=\"text\" id=\"attachfiles_name\" placeholder=\"$fileNamePlaceholder\" required style=\"width: 20em; margin-right: 0.5em\">";
                $uploadForm .= "<input type=\"submit\" value=\"$uploadSubmitText\" id=\"attachfiles_upload\">";
                $uploadForm .= "</p>";
                $uploadForm .= "</form>\n";
                $out->addHTML($uploadForm);
            } else if (!$user->isRegistered()) {
                $out->addWikiMsg("attachfiles-no-upload-permissions");
            }
        }
    }

    public static function onContentAlterParserOutput($content, $title, $parserOutput) {
        global $wgContentNamespaces;
        global $wgAFIgnoredPages;

        $id = $title->getArticleID();
        $namespace = $title->getNamespace();
        if ($id > 0 && !in_array($title->getPrefixedText(), $wgAFIgnoredPages ?? []) && in_array($namespace, $wgContentNamespaces)) {
            $services = MediaWikiServices::getInstance();
            $lb = $services->getDBLoadBalancer();
            $dbr = $lb->getConnectionRef(DB_REPLICA);

            $res = $dbr->select("attachfiles_attached", ["filename"], ["pageid" => $id], __METHOD__, []);
            foreach ($res as $row) {
                // Add to the images of the page (prevents MediaWiki from deleting it from the imagelinks table).
                $fileName = $row->filename;
                $parserOutput->addImage($fileName);
            }
        }
    }

    public static function onUploadComplete($uploadBase) {
        $context = RequestContext::getMain();
        $id = $context->getRequest()->getIntOrNull("attachfiles_id");
        $displayName = $context->getRequest()->getText("attachfiles_displayname");
        $namespace = $context->getRequest()->getIntOrNull("attachfiles_namespace");
        if (!isset($id) || !isset($displayName) || !isset($namespace)) {
            // User uploaded from unkown source, so we ignore it.
            return;
        }

        $file = $uploadBase->getLocalFile();

        $services = MediaWikiServices::getInstance();
        $lb = $services->getDBLoadBalancer();
        $dbr = $lb->getConnectionRef(DB_PRIMARY);

        $dbr->insert("attachfiles_attached", ["pageid" => $id, "filename" => $file->getName(), "displayname" => $displayName], __METHOD__, ["IGNORE"]);
        $dbr->insert("imagelinks", ["il_from" => $id, "il_from_namespace" => $namespace, "il_to" => $file->getName()], __METHOD__, ["IGNORE"]);
    }

    public static function onFileDeleteComplete($file, $oldimage, $article, $user, $reason) {
        $services = MediaWikiServices::getInstance();
        $lb = $services->getDBLoadBalancer();
        $dbr = $lb->getConnectionRef(DB_PRIMARY);

        $dbr->delete("attachfiles_attached", ["filename" => $file->getName()]);
        $dbr->delete("imagelinks", ["il_to" => $file->getName()]);
    }

    public static function onArticleDeleteComplete(&$article, &$user, $reason, $id, $content, $logEntry, $archivedRevisionCount) {
        global $wgContentNamespaces;
        global $wgAFIgnoredPages;

        $title = $article->getTitle();
        $namespace = $title->getNamespace();
        if ($id > 0 && !in_array($title->getPrefixedText(), $wgAFIgnoredPages ?? []) && in_array($namespace, $wgContentNamespaces)) {
            $context = RequestContext::getMain();
            $deleteReason = $context->msg("attachfiles-delete-reason");

            $services = MediaWikiServices::getInstance();
            $lb = $services->getDBLoadBalancer();
            $dbr = $lb->getConnectionRef(DB_REPLICA);

            $res = $dbr->select("attachfiles_attached", ["filename"], ["pageid" => $id], __METHOD__, []);
            foreach ($res as $row) {
                $fileName = $row->filename;
                $file = $services->getRepoGroup()->findFile($fileName);
                $title = $file->getTitle();
                $oldimage = null;
                FileDeleteForm::doDelete($title, $file, $oldimage, $deleteReason, false, $user, []);
            }
        }
    }

    public static function onPageMoveCompleting($old, $new, $userIdentity, $pageid, $redirid, $reason, $revision) {
        if ($old->inNamespace(NS_FILE)) {
            $services = MediaWikiServices::getInstance();
            $lb = $services->getDBLoadBalancer();
            $dbr = $lb->getConnectionRef(DB_PRIMARY);

            if ($new->inNamespace(NS_FILE)) {
                $dbr->update("attachfiles_attached", ["filename" => $new->getDBkey()], ["filename" => $old->getDBkey()]);
                $dbr->update("imagelinks", ["il_to" => $new->getDBkey()], ["il_to" => $old->getDBkey()]);
            } else {
                // Is it even possible to move a file page to a non-file page?
                $dbr->delete("attachfiles_attached", ["filename" => $old->getDBkey()]);
                $dbr->delete("imagelinks", ["il_to" => $old->getDBkey()]);
            }
        }
    }
}
