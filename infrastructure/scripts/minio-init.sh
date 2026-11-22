#!/bin/sh
# Idempotent bucket bootstrap: public catalog media, private documents + readiness marker.
set -eu

mc alias set snowego "${S3_ENDPOINT_INTERNAL}" "${S3_ROOT_USER}" "${S3_ROOT_PASSWORD}" >/dev/null

mc mb --ignore-existing "snowego/${S3_PUBLIC_BUCKET}"
mc mb --ignore-existing "snowego/${S3_PRIVATE_BUCKET}"
mc anonymous set download "snowego/${S3_PUBLIC_BUCKET}"
mc anonymous set none "snowego/${S3_PRIVATE_BUCKET}"

# Application credential limited to the two Snowego buckets.
cat > /tmp/snowego-app.json <<POLICY
{
  "Version": "2012-10-17",
  "Statement": [{
    "Effect": "Allow",
    "Action": ["s3:GetObject", "s3:PutObject", "s3:DeleteObject", "s3:ListBucket", "s3:GetBucketLocation"],
    "Resource": [
      "arn:aws:s3:::${S3_PUBLIC_BUCKET}", "arn:aws:s3:::${S3_PUBLIC_BUCKET}/*",
      "arn:aws:s3:::${S3_PRIVATE_BUCKET}", "arn:aws:s3:::${S3_PRIVATE_BUCKET}/*"
    ]
  }]
}
POLICY
mc admin policy create snowego snowego-app /tmp/snowego-app.json >/dev/null 2>&1 || true
mc admin user add snowego "${S3_ACCESS_KEY_ID}" "${S3_SECRET_ACCESS_KEY}" >/dev/null
mc admin policy attach snowego snowego-app --user "${S3_ACCESS_KEY_ID}" >/dev/null 2>&1 || true

echo ok | mc pipe "snowego/${S3_PRIVATE_BUCKET}/.readiness" >/dev/null
echo "minio-init: buckets ready"
