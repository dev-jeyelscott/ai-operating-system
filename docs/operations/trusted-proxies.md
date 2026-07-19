# Trusted Proxy Deployment Configuration

## Purpose

Laravel accepts forwarded client IP, host, port, and HTTPS information only
from infrastructure included in the configured proxy trust boundary.

Forwarded headers from untrusted remote addresses are ignored.

## Environment variables

```dotenv
TRUSTED_PROXIES=
TRUSTED_PROXY_HEADERS=x_forwarded
TRUSTED_PROXIES
```

`Supported values:`

- Empty: no explicitly configured proxy.
- Exact addresses:
`10.20.30.40,10.20.30.41`
- CIDR ranges:
`10.0.0.0/8,172.16.0.0/12`
- Wildcard:
`*`

Prefer exact IP addresses or CIDR ranges.

Wildcard trust may be used only when direct application access is blocked and
all traffic must pass through infrastructure that overwrites or strips
client-provided forwarding headers.

`TRUSTED_PROXY_HEADERS`

Supported modes:

- x_forwarded
- forwarded
- aws_elb

Configure only the convention produced by the approved ingress infrastructure.

## Local development

Laravel Sail currently exposes the application directly over local HTTP.

```dotenv
TRUSTED_PROXIES=
TRUSTED_PROXY_HEADERS=x_forwarded
```

Forwarded headers are not required for the normal local environment.

## Known reverse proxy example

```dotenv
TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12
TRUSTED_PROXY_HEADERS=x_forwarded
```

The reverse proxy must overwrite:

- `X-Forwarded-For`
- `X-Forwarded-Host`
- `X-Forwarded-Port`
- `X-Forwarded-Proto`

## AWS Application Load Balancer example

```dotenv
`TRUSTED_PROXIES=*`
`TRUSTED_PROXY_HEADERS=aws_elb`
```

This configuration requires all of the following controls:

1. Application instances are not publicly reachable.
2. Security groups permit application ingress only from the load balancer.
3. The load balancer overwrites or strips client-supplied forwarding headers.
4. Health checks use the same approved ingress path or an explicitly documented internal path.
5. QA verifies the actual client IP and HTTPS scheme through the deployed load balancer.

## Approved production topology

Complete this section before production deployment.

| Property                          | Approved value       |
| --------------------------------- | -------------------- |
| Ingress provider                  | Pending confirmation |
| Proxy IP/CIDR ownership           | Pending confirmation |
| Header mode                       | Pending confirmation |
| Direct application access blocked | Pending confirmation |
| Forwarded headers overwritten     | Pending confirmation |
| Verification date                 | Pending confirmation |
| Verified by                       | Pending confirmation |


Production deployment must remain blocked until these values are confirmed.

## Verification

Through the approved proxy:

- `Request::ip()` resolves the original client.
- `Request::isSecure()` returns `true` for external HTTPS traffic.
- Generated absolute URLs use the expected HTTPS scheme and host.
- Two external clients behind one proxy receive independent login limiter buckets.

From an untrusted direct address:

- `X-Forwarded-For` cannot override the remote address.
- `X-Forwarded-Proto` cannot force HTTPS detection.
- `Forwarded` cannot override request context unless RFC mode is selected.