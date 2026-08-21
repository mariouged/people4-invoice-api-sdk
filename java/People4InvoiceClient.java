import java.io.IOException;
import java.io.UncheckedIOException;
import java.net.Socket;
import java.net.URI;
import java.net.URISyntaxException;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpRequest.BodyPublisher;
import java.net.http.HttpRequest.BodyPublishers;
import java.net.http.HttpResponse;
import java.net.http.HttpResponse.BodyHandlers;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.security.KeyManagementException;
import java.security.NoSuchAlgorithmException;
import java.security.SecureRandom;
import java.security.cert.X509Certificate;
import java.time.Duration;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Collections;

import javax.net.ssl.SSLContext;
import javax.net.ssl.SSLEngine;
import javax.net.ssl.SSLParameters;
import javax.net.ssl.TrustManager;
import javax.net.ssl.X509ExtendedTrustManager;

final class People4HttpClient {

    private final HttpClient httpClient;

    People4HttpClient(boolean disableSslVerification) {
        HttpClient.Builder builder = HttpClient.newBuilder()
                .connectTimeout(Duration.ofSeconds(15));
        if (disableSslVerification) { // not recommended for production
            try {
                builder.sslContext(trustAllSslContext());
                SSLParameters sslParameters = new SSLParameters();
                sslParameters.setEndpointIdentificationAlgorithm("");
                builder.sslParameters(sslParameters);
            } catch (NoSuchAlgorithmException | KeyManagementException e) {
                throw new RuntimeException("Unable to disable SSL verification.", e);
            }
        }
        this.httpClient = builder.build();
    }

    // send an invoice and received ubl, peppol and people4Id in response
    Map<String, Object> sendInvoice(String url, String payload, String jwt) {
        String response = httpRequest(url, "POST", payload, jwt);
        if (response == null || response.isBlank()) {
            throw new RuntimeException("Response failed to retrieve JWT token from People4 Authentication API.");
        }
        return asObject(Json.parse(response), "invoice");
    }

    String retrieveToken(String url, RetrieveTokenPayload payload) {
        String response = httpRequest(url, "PUT", Json.encode(payload.toMap()), payload.getApiKey());
        if (response == null || response.isBlank()) {
            throw new RuntimeException("Response failed to retrieve JWT token from People4 Authentication API.");
        }
        Map<String, Object> decoded = asObject(Json.parse(response), "token");
        Object token = decoded.get("token");
        if (!(token instanceof String)) {
            throw new RuntimeException("JWT token not present in response.");
        }
        return (String) token;
    }

    private String httpRequest(String url, String method, String payload, String bearer) {
        BodyPublisher bodyPublisher = (payload == null || payload.isEmpty())
                ? BodyPublishers.noBody()
                : BodyPublishers.ofString(payload, StandardCharsets.UTF_8);

        HttpRequest request = HttpRequest.newBuilder()
                .uri(URI.create(url))
                .timeout(Duration.ofSeconds(15))
                .header("Content-Type", "application/json")
                .header("Accept", "application/json")
                .header("Authorization", "Bearer " + bearer) // use for jwt and api key
                .method(method, bodyPublisher)
                .build();

        try {
            HttpResponse<String> response = httpClient.send(request, BodyHandlers.ofString(StandardCharsets.UTF_8));
            int statusCode = response.statusCode();
            String body = response.body();
            if (statusCode >= 400 || statusCode < 200) {
                throw new RuntimeException("HTTP request failed with status code " + statusCode + ": " + body);
            }
            return body;
        } catch (IOException e) {
            throw new UncheckedIOException("HTTP request failed: " + e.getMessage(), e);
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
            throw new RuntimeException("HTTP request interrupted.", e);
        }
    }

    @SuppressWarnings("unchecked")
    private static Map<String, Object> asObject(Object decoded, String context) {
        if (!(decoded instanceof Map)) {
            throw new RuntimeException("Unexpected " + context + " response payload.");
        }
        return (Map<String, Object>) decoded;
    }

    // X509ExtendedTrustManager is required here: a plain X509TrustManager is still
    // wrapped by the JDK with its own hostname check, ignoring disabled endpoint identification.
    private static SSLContext trustAllSslContext() throws NoSuchAlgorithmException, KeyManagementException {
        TrustManager[] trustAllCerts = new TrustManager[] {
                new X509ExtendedTrustManager() {
                    public X509Certificate[] getAcceptedIssuers() { return new X509Certificate[0]; }
                    public void checkClientTrusted(X509Certificate[] certs, String authType) {}
                    public void checkServerTrusted(X509Certificate[] certs, String authType) {}
                    public void checkClientTrusted(X509Certificate[] certs, String authType, Socket socket) {}
                    public void checkServerTrusted(X509Certificate[] certs, String authType, Socket socket) {}
                    public void checkClientTrusted(X509Certificate[] certs, String authType, SSLEngine engine) {}
                    public void checkServerTrusted(X509Certificate[] certs, String authType, SSLEngine engine) {}
                }
        };
        SSLContext sslContext = SSLContext.getInstance("TLS");
        sslContext.init(null, trustAllCerts, new SecureRandom());
        return sslContext;
    }
}

final class RetrieveTokenPayload {

    private final String apiKey;
    private final String domain;
    private final String legalName;
    private final String vatId;

    RetrieveTokenPayload(String apiKey, String domain, String legalName, String vatId) {
        this.apiKey = apiKey;
        this.domain = domain;
        this.legalName = legalName;
        this.vatId = vatId;
    }

    Map<String, Object> toMap() {
        Map<String, Object> map = new LinkedHashMap<>();
        map.put("domain", domain);
        map.put("legalname", legalName);
        map.put("vatId", vatId);
        return map;
    }

    String getApiKey() {
        return apiKey;
    }
}

// minimal dependency-free JSON encoder/decoder (objects, arrays, strings, numbers, booleans, null)
final class Json {

    private Json() {}

    static String encode(Object value) {
        StringBuilder sb = new StringBuilder();
        write(value, sb);
        return sb.toString();
    }

    @SuppressWarnings("unchecked")
    private static void write(Object value, StringBuilder sb) {
        if (value == null) {
            sb.append("null");
        } else if (value instanceof String s) {
            writeString(s, sb);
        } else if (value instanceof Number || value instanceof Boolean) {
            sb.append(value);
        } else if (value instanceof Map) {
            sb.append('{');
            boolean first = true;
            for (Map.Entry<String, Object> entry : ((Map<String, Object>) value).entrySet()) {
                if (!first) {
                    sb.append(',');
                }
                first = false;
                writeString(entry.getKey(), sb);
                sb.append(':');
                write(entry.getValue(), sb);
            }
            sb.append('}');
        } else if (value instanceof List) {
            sb.append('[');
            boolean first = true;
            for (Object item : (List<Object>) value) {
                if (!first) {
                    sb.append(',');
                }
                first = false;
                write(item, sb);
            }
            sb.append(']');
        } else {
            throw new IllegalArgumentException("Unsupported JSON value type: " + value.getClass());
        }
    }

    private static void writeString(String s, StringBuilder sb) {
        sb.append('"');
        for (int i = 0; i < s.length(); i++) {
            char c = s.charAt(i);
            switch (c) {
                case '"' -> sb.append("\\\"");
                case '\\' -> sb.append("\\\\");
                case '\n' -> sb.append("\\n");
                case '\r' -> sb.append("\\r");
                case '\t' -> sb.append("\\t");
                default -> {
                    if (c < 0x20) {
                        sb.append(String.format("\\u%04x", (int) c));
                    } else {
                        sb.append(c);
                    }
                }
            }
        }
        sb.append('"');
    }

    static Object parse(String json) {
        Parser parser = new Parser(json);
        Object value = parser.parseValue();
        parser.skipWhitespace();
        if (!parser.isAtEnd()) {
            throw new RuntimeException("Unexpected trailing content in JSON response.");
        }
        return value;
    }

    private static final class Parser {
        private final String json;
        private int pos;

        Parser(String json) {
            this.json = json;
        }

        boolean isAtEnd() {
            return pos >= json.length();
        }

        void skipWhitespace() {
            while (pos < json.length() && Character.isWhitespace(json.charAt(pos))) {
                pos++;
            }
        }

        Object parseValue() {
            skipWhitespace();
            char c = peek();
            return switch (c) {
                case '{' -> parseObject();
                case '[' -> parseArray();
                case '"' -> parseString();
                case 't', 'f' -> parseBoolean();
                case 'n' -> parseNull();
                default -> parseNumber();
            };
        }

        Map<String, Object> parseObject() {
            expect('{');
            Map<String, Object> map = new LinkedHashMap<>();
            skipWhitespace();
            if (peek() == '}') {
                pos++;
                return map;
            }
            while (true) {
                skipWhitespace();
                String key = parseString();
                skipWhitespace();
                expect(':');
                Object value = parseValue();
                map.put(key, value);
                skipWhitespace();
                char next = json.charAt(pos++);
                if (next == '}') {
                    break;
                }
                if (next != ',') {
                    throw new RuntimeException("Malformed JSON object at position " + pos);
                }
            }
            return map;
        }

        List<Object> parseArray() {
            expect('[');
            List<Object> list = new java.util.ArrayList<>();
            skipWhitespace();
            if (peek() == ']') {
                pos++;
                return list;
            }
            while (true) {
                list.add(parseValue());
                skipWhitespace();
                char next = json.charAt(pos++);
                if (next == ']') {
                    break;
                }
                if (next != ',') {
                    throw new RuntimeException("Malformed JSON array at position " + pos);
                }
            }
            return list;
        }

        String parseString() {
            expect('"');
            StringBuilder sb = new StringBuilder();
            while (true) {
                char c = json.charAt(pos++);
                if (c == '"') {
                    break;
                }
                if (c == '\\') {
                    char escape = json.charAt(pos++);
                    switch (escape) {
                        case '"' -> sb.append('"');
                        case '\\' -> sb.append('\\');
                        case '/' -> sb.append('/');
                        case 'b' -> sb.append('\b');
                        case 'f' -> sb.append('\f');
                        case 'n' -> sb.append('\n');
                        case 'r' -> sb.append('\r');
                        case 't' -> sb.append('\t');
                        case 'u' -> {
                            String hex = json.substring(pos, pos + 4);
                            sb.append((char) Integer.parseInt(hex, 16));
                            pos += 4;
                        }
                        default -> throw new RuntimeException("Invalid escape sequence in JSON string.");
                    }
                } else {
                    sb.append(c);
                }
            }
            return sb.toString();
        }

        Boolean parseBoolean() {
            if (json.startsWith("true", pos)) {
                pos += 4;
                return Boolean.TRUE;
            }
            if (json.startsWith("false", pos)) {
                pos += 5;
                return Boolean.FALSE;
            }
            throw new RuntimeException("Invalid boolean literal in JSON at position " + pos);
        }

        Object parseNull() {
            if (json.startsWith("null", pos)) {
                pos += 4;
                return null;
            }
            throw new RuntimeException("Invalid null literal in JSON at position " + pos);
        }

        Number parseNumber() {
            int start = pos;
            while (pos < json.length() && "-+.eE0123456789".indexOf(json.charAt(pos)) >= 0) {
                pos++;
            }
            String number = json.substring(start, pos);
            if (number.isEmpty()) {
                throw new RuntimeException("Invalid number literal in JSON at position " + pos);
            }
            if (number.contains(".") || number.contains("e") || number.contains("E")) {
                return Double.parseDouble(number);
            }
            return Long.parseLong(number);
        }

        char peek() {
            if (isAtEnd()) {
                throw new RuntimeException("Unexpected end of JSON input.");
            }
            return json.charAt(pos);
        }

        void expect(char expected) {
            char actual = json.charAt(pos++);
            if (actual != expected) {
                throw new RuntimeException("Expected '" + expected + "' but found '" + actual + "' at position " + (pos - 1));
            }
        }
    }
}

final class InvoiceResponse {

    private final String peopleId;
    private final String ubl;
    private final String peppol;
    private final List<String> messages;

    InvoiceResponse(String peopleId, String ubl, String peppol, List<String> messages) {
        this.peopleId = peopleId;
        this.ubl = ubl;
        this.peppol = peppol;
        this.messages = messages != null ? messages : Collections.<String>emptyList();
    }

    String getPeopleId() {
        return peopleId;
    }

    String getUbl() {
        return ubl;
    }

    String getPeppol() {
        return peppol;
    }

    List<String> getMessages() {
        return messages;
    }
}

// ---------------------------------------------------------------------------
// CLI entry point
// ---------------------------------------------------------------------------

public final class People4InvoiceClient {

    private String jwt;

    // retrieve JWT token from Authentication API
    public String getToken(RetrieveTokenPayload payload) {
        People4HttpClient httpCli = new People4HttpClient(true); // only for testing, not recommended for production
        this.jwt = httpCli.retrieveToken("https://app.people4.eu/auth-api/token", payload);
        return this.jwt;
    }

    // send invoice payload to Invoice API POST method
    @SuppressWarnings("unchecked")
    public InvoiceResponse createUblInvoice(String invoiceJsonRaw) {
        People4HttpClient httpCli = new People4HttpClient(true); // only for testing, not recommended for production
        Map<String, Object> invoiceCreated = httpCli.sendInvoice("https://app.people4.eu/invoice-api/invoice/v1", invoiceJsonRaw, this.jwt);
        // System.out.println("DEBUG: Invoice created response: " + Json.encode(invoiceCreated));
        return new InvoiceResponse(
            String.valueOf(invoiceCreated.get("people4Id")),
            String.valueOf(invoiceCreated.get("ubl")),
            String.valueOf(invoiceCreated.get("peppol")),
            (List<String>) invoiceCreated.get("messages")
        );
    }

    // resolve inputs/invoice-v1-minimal-test.json relative to this source/class file, like PHP's __DIR__
    private static Path resolveDefaultInvoiceFile() throws URISyntaxException {
        Path location = Path.of(People4InvoiceClient.class.getProtectionDomain().getCodeSource().getLocation().toURI());
        Path javaDir = Files.isDirectory(location) ? location : location.getParent();
        return javaDir.resolve("../inputs/invoice-v1-minimal-test.json").normalize();
    }

    public static void main(String[] args) {
        try {
            People4InvoiceClient easyComplianceCli = new People4InvoiceClient();
            System.out.println("Trying to retrieve JWT token from Authentication API");
            RetrieveTokenPayload payload = new RetrieveTokenPayload(
                "1c8f86e857cb65b20b26c481b0e2d2d1bf0b93a93aa7ccc35704c37ee63c597b",
                "acme-corporation.eu",
                "ACME Corp",
                "NL8200.98.395.B.01");
            easyComplianceCli.getToken(payload);
            // System.out.println("OK: JWT token retrieved: '" + jwt + "'");

            System.out.println("Read json invoice minimal test file and send to People4 Invoice API");
            Path invoiceFile = resolveDefaultInvoiceFile();
            String invoiceJsonRaw = Files.readString(invoiceFile, StandardCharsets.UTF_8);
            System.out.println("Trying to send invoice payload to Invoice API to generate UBL and PEPPOL invoice");
            InvoiceResponse invoiceCreated = easyComplianceCli.createUblInvoice(invoiceJsonRaw);
            // System.out.println("DEBUG: Invoice created response: " + Json.encode(invoiceCreated));

            if (invoiceCreated.getPeopleId() == null) {
                throw new RuntimeException("People4 ID not present in response.");
            }
            if (invoiceCreated.getUbl() == null) {
                throw new RuntimeException("UBL not present in response.");
            }
            if (invoiceCreated.getPeppol() == null) {
                throw new RuntimeException("PEPPOL not present in response.");
            }

            List<String> messages = invoiceCreated.getMessages();
            if (messages != null && !messages.isEmpty()) {
                String lastMessage = null;
                for (String message : messages) {
                    System.out.println("Error Message: " + message);
                    lastMessage = message;
                }
                throw new RuntimeException("Error on generate UBL or PEPPOL message: " + lastMessage);
            }

            System.out.println("OK: Invoice created with People4 ID: " + invoiceCreated.getPeopleId());
            System.out.println("OK: UBL invoice created with length: " + invoiceCreated.getUbl().length());
            System.out.println("OK: PEPPOL invoice created with length: " + invoiceCreated.getPeppol().length());
            System.exit(0);
        } catch (Throwable e) {
            System.err.println("Fatal: " + e.getMessage());
            System.out.println("Error: " + e.getMessage());
            System.exit(1);
        }
    }
}
