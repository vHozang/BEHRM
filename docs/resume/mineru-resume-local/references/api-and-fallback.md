# Resume API and Fallback

## Production endpoint order

Use this order on the VPS:

```dotenv
AUTORECRUIT_MAC_URL=http://100.105.84.89:8000
AUTORECRUIT_URL=http://100.105.84.89:8000
AUTORECRUIT_FALLBACK_URLS=http://100.95.129.101:8000
AUTORECRUIT_CONNECT_TIMEOUT=5
AUTORECRUIT_TIMEOUT=120
```

- `100.105.84.89`: preferred Mac resume-backend.
- `100.95.129.101`: Windows fallback.
- MinerU is internal to each local Docker network and is not called directly by the VPS.

The ordered endpoint list must be used for:

- `POST /screen`
- `POST /feedback`
- `GET /feedback/stats`
- `GET /feedback/adjustments`
- `POST /outcomes`
- `GET /health`

Continue to the next endpoint on connection errors, timeouts, non-2xx responses, or invalid JSON payloads. Stop after the first successful valid response.

## Connectivity checks

From the VPS:

```bash
tailscale ping 100.105.84.89
tailscale ping 100.95.129.101
curl --connect-timeout 5 --max-time 15 -fsS http://100.105.84.89:8000/health
curl --connect-timeout 5 --max-time 15 -fsS http://100.95.129.101:8000/health
```

The Laravel admin health endpoint must report the first healthy URL as `selected_url`:

```text
GET /api/v1/settings/integrations/autorecruit/health
```

When both machines are online, `selected_url` must be the Mac address. When the Mac is offline, requests must continue through Windows. Audit feedback retries because a timeout after a successful remote write can require idempotency handling.
