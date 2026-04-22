<?php

namespace App\Enums;

enum ReviewStatus: string
{
    case AutoGraded  = 'auto_graded';
    case NeedsReview = 'needs_review';
    case Reviewed    = 'reviewed';
}
