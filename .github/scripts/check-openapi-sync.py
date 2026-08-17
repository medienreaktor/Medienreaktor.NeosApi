#!/usr/bin/env python3
"""
Fail when Configuration/Routes.yaml and the OpenAPI document disagree.

Compares the (HTTP method, path) pairs declared as routes against the
operations documented in Resources/Private/OpenApi/openapi.yaml, in both
directions. Placeholder names must match too ({nodeAddress} vs {id} is a
mismatch) - the spec's parameter names are part of the contract.

This catches added, removed and renamed endpoints. It cannot catch drift
inside request/response bodies - that remains a review concern (and a job
for response validation in the smoke tests).

Runs in CI (see .github/workflows/api-docs.yml) and locally:
    python3 .github/scripts/check-openapi-sync.py
"""

import os
import sys

try:
    import yaml
except ImportError:
    sys.exit("PyYAML is required: pip install pyyaml")

HTTP_METHODS = {"get", "put", "post", "delete", "options", "head", "patch", "trace"}

root = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

with open(os.path.join(root, "Configuration", "Routes.yaml")) as f:
    routes = yaml.safe_load(f)

with open(os.path.join(root, "Resources", "Private", "OpenApi", "openapi.yaml")) as f:
    spec = yaml.safe_load(f)

route_ops = set()
for route in routes:
    for method in route.get("httpMethods", []):
        route_ops.add((method.upper(), "/" + route["uriPattern"]))

spec_ops = set()
for path, path_item in spec.get("paths", {}).items():
    for key in path_item:
        if key in HTTP_METHODS:
            spec_ops.add((key.upper(), path))

undocumented = sorted(route_ops - spec_ops)
phantom = sorted(spec_ops - route_ops)

if undocumented:
    print("Routes missing from the OpenAPI document:")
    for method, path in undocumented:
        print(f"  {method:7} {path}")
if phantom:
    print("OpenAPI operations without a matching route:")
    for method, path in phantom:
        print(f"  {method:7} {path}")

if undocumented or phantom:
    print(f"\nOUT OF SYNC: {len(undocumented)} undocumented, {len(phantom)} phantom.")
    print("Update Resources/Private/OpenApi/openapi.yaml alongside Routes.yaml.")
    sys.exit(1)

print(f"In sync: {len(route_ops)} operations in both Routes.yaml and the OpenAPI document.")
