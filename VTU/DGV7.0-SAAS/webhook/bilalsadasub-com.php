<?php
// Disabled until the provider supplies and documents a verifiable
// HMAC/signature check over the raw callback body.
http_response_code(503);
header("Content-Type: application/json");
echo json_encode(array(
	"status" => "failed",
	"desc" => "Webhook verification is not configured"
));
