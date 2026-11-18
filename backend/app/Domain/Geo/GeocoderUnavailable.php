<?php

namespace App\Domain\Geo;

use RuntimeException;

/** Fournisseur de géocodage indisponible (panne, délai dépassé, quota, clé refusée). */
class GeocoderUnavailable extends RuntimeException {}
