"""
People4 Invoice API CLI

Retrieves a JWT from the People4 Authentication API, then sends an invoice
payload (JSON) to the People4 Invoice API and prints the UBL/PEPPOL result.

Usage:
    python python/people4_invoice_cli.py
"""

from __future__ import annotations

import json
import ssl
import sys
import urllib.error
import urllib.request
from dataclasses import dataclass
from pathlib import Path
from typing import Optional


class People4HttpClient:
    def __init__(self, disable_ssl_verification: bool = False) -> None:
        self._disable_ssl_verification = disable_ssl_verification

    # send an invoice and receive ubl, peppol and people4Id in response
    def send_invoice(self, url: str, payload: str, jwt: str) -> dict:
        response = self._http_request(url, "POST", payload, jwt)
        if not response:
            raise RuntimeError("Response failed to retrieve JWT token from People4 Authentication API.")
        return json.loads(response)

    def retrieve_token(self, url: str, payload: "RetrieveTokenPayload") -> str:
        response = self._http_request(url, "PUT", json.dumps(payload.to_dict()), payload.api_key)
        if not response:
            raise RuntimeError("Response failed to retrieve JWT token from People4 Authentication API.")
        token = json.loads(response).get("token")
        if not token:
            raise RuntimeError("JWT token not present in response.")
        return token

    def _http_request(self, url: str, method: str, payload: str, bearer: str) -> str:
        body = payload.encode("utf-8") if payload else None
        request = urllib.request.Request(
            url=url,
            data=body,
            method=method,
            headers={
                "Content-Type": "application/json",
                "Accept": "application/json",
                "Content-Length": str(len(body) if body else 0),
                "Authorization": f"Bearer {bearer}",  # use for jwt and api key
            },
        )
        context = None
        if self._disable_ssl_verification:
            # curl -k insecure on DEVELOPMENT, not recommended for production
            context = ssl.create_default_context()
            context.check_hostname = False
            context.verify_mode = ssl.CERT_NONE
        try:
            with urllib.request.urlopen(request, timeout=15, context=context) as response:
                return response.read().decode("utf-8")
        except urllib.error.HTTPError as exc:
            error_body = exc.read().decode("utf-8")
            raise RuntimeError(f"HTTP request failed with status code {exc.code}: {error_body}") from exc
        except urllib.error.URLError as exc:
            raise RuntimeError(f"HTTP request failed: {exc.reason}") from exc


@dataclass(frozen=True)
class RetrieveTokenPayload:
    api_key: str
    domain: str
    legal_name: str
    vat_id: str

    def to_dict(self) -> dict:
        return {
            "domain": self.domain,
            "legalname": self.legal_name,
            "vatId": self.vat_id,
        }


# ---------------------------------------------------------------------------
# CLI entry point
# ---------------------------------------------------------------------------


class People4InvoiceCli:
    def __init__(self) -> None:
        self._jwt: Optional[str] = None

    # retrieve JWT token from Authentication API
    def get_token(self) -> str:
        http_cli = People4HttpClient(disable_ssl_verification=True)  # only for testing, not recommended for production
        payload = RetrieveTokenPayload(
            api_key="1c8f86e857cb65b20b26c481b0e2d2d1bf0b93a93aa7ccc35704c37ee63c597b",
            domain="acme-corporation.eu",
            legal_name="ACME Corp",
            vat_id="NL8200.98.395.B.01",
        )
        self._jwt = http_cli.retrieve_token(
            url="https://app.people4.eu/auth-api/token",
            payload=payload,
        )
        return self._jwt

    # send invoice payload to Invoice API POST method
    def create_ubl_invoice(self, invoice_json_raw: str) -> dict:
        http_cli = People4HttpClient(disable_ssl_verification=True)  # only for testing, not recommended for production
        return http_cli.send_invoice(
            url="https://app.people4.eu/invoice-api/invoice/v1",
            payload=invoice_json_raw,
            jwt=self._jwt,
        )


def main() -> int:
    easy_compliance_cli = People4InvoiceCli()
    try:
        print("Trying to retrieve JWT token from Authentication API")
        easy_compliance_cli.get_token()
        # print(f"OK: JWT token retrieved: '{jwt}'")

        print("Read json invoice minimal test file and send to People4 Invoice API")
        invoice_file = Path(__file__).parent.parent / "inputs" / "invoice-v1-minimal-test.json"
        invoice_json_raw = invoice_file.read_text(encoding="utf-8")
        print("Trying to send invoice payload to Invoice API to generate UBL and PEPPOL invoice")
        invoice_created = easy_compliance_cli.create_ubl_invoice(invoice_json_raw)
        # print(f"DEBUG: Invoice created response: {json.dumps(invoice_created)}")

        if "people4Id" not in invoice_created:
            raise RuntimeError("People4 ID not present in response.")
        if "ubl" not in invoice_created:
            raise RuntimeError("UBL not present in response.")
        if "peppol" not in invoice_created:
            raise RuntimeError("PEPPOL not present in response.")

        messages = invoice_created.get("messages")
        if messages:
            message = None
            for message in messages:
                print(f"Error Message: {message}")
            raise RuntimeError(f"Error on generate UBL or PEPPOL message: {message}")

        if invoice_created.get("people4Id"):
            print(f"OK: Invoice created with People4 ID: {invoice_created['people4Id']}")
        if invoice_created.get("ubl"):
            print(f"OK: UBL invoice created with length: {len(invoice_created['ubl'])}")
        if invoice_created.get("peppol"):
            print(f"OK: PEPPOL invoice created with length: {len(invoice_created['peppol'])}")
        return 0
    except Exception as exc:  # noqa: BLE001
        print(f"Fatal: {exc}", file=sys.stderr)
        print(f"Error: {exc}")
        return 1


if __name__ == "__main__":
    sys.exit(main())
