#!/bin/bash

CONFIG_FILE="config.sh"

function check_config() {
    if [ ! -f "$CONFIG_FILE" ]; then
        echo "Error: Configuration file '$CONFIG_FILE' not found."
        echo "Please create a '$CONFIG_FILE' with the following content:"
        cat << EOF
#!/bin/bash

AWS_ACCESS_KEY_ID="YOUR_ACCESS_KEY_ID"
AWS_SECRET_ACCESS_KEY="YOUR_SECRET_ACCESS_KEY"
HOSTED_ZONE_ID="YOUR_HOSTED_ZONE_ID"
EOF
        exit 1
    fi
}

function aws_upsert_a_record() {
    # Check for config file
    check_config

    # Load configuration from the separate file
    source "$CONFIG_FILE"

    # Parse command-line arguments
    if [ $# -ne 2 ]; then
        echo "Usage: $0 <domain_name> <new_ip>"
        exit 1
    fi

    DOMAIN_NAME="$1"
    NEW_IP="$2"

    # Validate required variables
    if [[ -z "$AWS_ACCESS_KEY_ID" || -z "$AWS_SECRET_ACCESS_KEY" || -z "$HOSTED_ZONE_ID" ]]; then
        echo "Error: Missing required configuration variables in $CONFIG_FILE."
        check_config
    fi

    # Constants
    RECORD_TYPE="A"
    REGION="us-east-1"
    SERVICE="route53"
    HOST="route53.amazonaws.com"
    ENDPOINT="https://$HOST/2013-04-01/hostedzone/$HOSTED_ZONE_ID/rrset"
    CANONICAL_URI="/2013-04-01/hostedzone/$HOSTED_ZONE_ID/rrset"

    # Generate timestamps
    TIME=$(date -u +"%Y%m%dT%H%M%SZ")
    DATE=$(date -u +"%Y%m%d")

    # Create request body for the UPSERT
    UPSERT_BODY=$(create_upsert_body)

    # AWS date and signature configuration
    REQUEST_TYPE="AWS4-HMAC-SHA256"
    ALGORITHM="AWS4-HMAC-SHA256"

    # Request specific details
    PAYLOAD_HASH=$(echo -n "$UPSERT_BODY" | openssl dgst -sha256 | sed 's/^.* //')

    # Build Canonical Request
    CANONICAL_HEADERS="content-type:application/xml\nhost:$HOST\nx-amz-date:$TIME\n"
    SIGNED_HEADERS="content-type;host;x-amz-date"
    CANONICAL_REQUEST=$(printf "POST\n$CANONICAL_URI\n\n$CANONICAL_HEADERS\n$SIGNED_HEADERS\n$PAYLOAD_HASH")

    # Create string to sign
    SCOPE="$DATE/$REGION/$SERVICE/aws4_request"
    HASH_CANONICAL_REQUEST=$(echo -n "$CANONICAL_REQUEST" | openssl dgst -sha256 | sed 's/^.* //')
    STRING_TO_SIGN=$(printf "$ALGORITHM\n$TIME\n$SCOPE\n$HASH_CANONICAL_REQUEST")

    # Generate the signature
    K_DATE=$(hmac_sha256 "AWS4$AWS_SECRET_ACCESS_KEY" "$DATE")
    K_REGION=$(hmac_sha256 "$K_DATE" "$REGION")
    K_SERVICE=$(hmac_sha256 "$K_REGION" "$SERVICE")
    K_SIGNING=$(hmac_sha256 "$K_SERVICE" "aws4_request")
    SIGNATURE=$(echo -n "$STRING_TO_SIGN" | openssl dgst -sha256 -mac HMAC -macopt "key:$K_SIGNING" | sed 's/^.* //')

    # Send request using curl and check for errors
    response=$(send_aws_request)
    exit_code=$?
    http_status=$(echo "$response" | head -n 1 | cut -d' ' -f2)

    if [ $exit_code -ne 0 ]; then
        echo "Error: curl command failed with exit code $exit_code"
        echo "Response: $response"
        exit $exit_code
    fi

    if [ "$http_status" != "200" ]; then
        echo "Error: AWS API request failed with HTTP status $http_status"
        echo "Response: $response"
        exit 1
    fi

    # Check for error in the XML response
    if echo "$response" | grep -q "<Error>"; then
        echo "Error: AWS API request failed"
        echo "Response: $response"
        exit 1
    fi

    echo "Route53 UPSERT request successful for $DOMAIN_NAME with IP $NEW_IP."
    echo "Response: $response"
}

function create_upsert_body() {
    cat <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<ChangeResourceRecordSetsRequest xmlns="https://route53.amazonaws.com/doc/2013-04-01/">
<ChangeBatch>
<Changes>
<Change>
<Action>UPSERT</Action>
<ResourceRecordSet>
<Name>$DOMAIN_NAME</Name>
<Type>$RECORD_TYPE</Type>
<TTL>300</TTL>
<ResourceRecords>
<ResourceRecord>
<Value>$NEW_IP</Value>
</ResourceRecord>
</ResourceRecords>
</ResourceRecordSet>
</Change>
</Changes>
</ChangeBatch>
</ChangeResourceRecordSetsRequest>
EOF
}

function send_aws_request() {
    local AUTHORIZATION_HEADER="$ALGORITHM Credential=$AWS_ACCESS_KEY_ID/$SCOPE, SignedHeaders=$SIGNED_HEADERS, Signature=$SIGNATURE"
    
    curl -s -S -i -X POST "$ENDPOINT" \
        -H "content-type: application/xml" \
        -H "host: $HOST" \
        -H "x-amz-date: $TIME" \
        -H "Authorization: $AUTHORIZATION_HEADER" \
        -d "$UPSERT_BODY"
}

# Function to calculate the signature
function hmac_sha256() {
    local key="$1"
    local data="$2"
    
    # Compute the HMAC with the binary key
    echo -n "$data" | openssl dgst -sha256 -mac HMAC -macopt "key:$key" -binary
}

# Main execution
aws_upsert_a_record "$@"
