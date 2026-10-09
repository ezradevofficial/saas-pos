<?php

namespace App\Core\Configuration\Http\Requests;

/**
 * LAY-06: GET config/{kind}/{config_document}: the document with its
 * published version, its draft (with the problems that block publishing)
 * and its history. Seeing the document is enough.
 */
class ShowConfigRequest extends ConfigDocumentRequest {}
