<?php

namespace App\Services\Admin;

use RuntimeException;

/** BE-03 — CAS statut/version perdu malgré le verrou : rollback puis 409 STATE_CONFLICT. */
final class TransitionConflict extends RuntimeException {}
