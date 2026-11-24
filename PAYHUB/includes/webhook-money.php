<?php
/** Existing PayHub conversion policy, shared without loading session/schema bootstrap. */
function to_minor_units($amount) {
    return (int)round(((float)$amount) * 100);
}
