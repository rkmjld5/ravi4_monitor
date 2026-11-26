/*
  ESP32 PATIENT MONITORING PROTOTYPE

  ALERT TEST VERSION - RENDER REMOTE SERVER

  MODE 1: SIMULATION_MODE = 1
          Fixed abnormal values are transmitted for testing alerts.
          No sensors required.

  MODE 2: SIMULATION_MODE = 0
          DHT11 + DS18B20 + MAX30100 + AD8232.

  ECG:
    Sample rate: 250 Hz
    Upload batch: up to 1250 samples

  Remote server:
    https://patient-remote.onrender.com

  Educational monitoring prototype only.
  Not a medical device.
*/

// =====================================================
// 1. SELECT OPERATING MODE
// =====================================================

#define SIMULATION_MODE 1

// 1 = Simulation mode
// 0 = Real sensor mode

// =====================================================
// 2. LIBRARIES
// =====================================================

#include <WiFi.h>
#include <WebServer.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>
#include <LittleFS.h>
#include <vector>
#include <string.h>
#include <math.h>

#if SIMULATION_MODE == 0
  #include <Wire.h>
  #include <DHT.h>
  #include <OneWire.h>
  #include <DallasTemperature.h>
  #include "MAX30100_PulseOximeter.h"
#endif

// =====================================================
// 3. DEVELOPER DEVICE IDENTITY + API CONFIGURATION
// =====================================================

static const char DEVICE_ID[] = "ESP32-01";

static const char ESP_API_TOKEN[] = "EspSwitch52026";

// RENDER REMOTE SERVER HTTPS API
static const char API_URL[] =
  "https://ravi2-monitor.onrender.com/api_save_readings.php";

const unsigned long API_UPLOAD_INTERVAL_MS = 5000;

unsigned long previousUploadMillis = 0;
bool firstUploadAttempt = true;

static const char* NETWORK_FILE = "/networks.txt";
static const int MAX_NETWORKS = 10;

struct WiFiEntry {
  String ssid;
  String password;
};

std::vector<WiFiEntry> savedNetworks;

bool littlefsReady = false;
bool wifiStackInitialized = false;

enum WiFiInputState {
  WIFI_INPUT_NONE,
  WIFI_INPUT_SSID,
  WIFI_INPUT_PASSWORD
};

WiFiInputState wifiInputState = WIFI_INPUT_NONE;

String pendingWiFiSSID = "";
int pendingWiFiEditIndex = -1;

WebServer server(80);

// =====================================================
// 4. ECG UPLOAD CONFIGURATION
// =====================================================

const int ECG_SAMPLE_RATE = 250;
const int ECG_UPLOAD_BUFFER_SIZE = 1250;
const int ECG_DISPLAY_BUFFER_SIZE = 512;

int16_t ecgUploadBuffer[ECG_UPLOAD_BUFFER_SIZE];

int ecgUploadHead = 0;
int ecgUploadCount = 0;

// =====================================================
// 5. LITTLEFS WIFI CONFIGURATION
// =====================================================

bool saveNetworks() {
  if (!littlefsReady) return false;

  File f = LittleFS.open(NETWORK_FILE, "w");
  if (!f) return false;

  for (size_t i = 0; i < savedNetworks.size(); i++) {
    f.println(savedNetworks[i].ssid + "|" +
              savedNetworks[i].password);
  }

  f.close();
  return true;
}

void loadNetworks() {
  savedNetworks.clear();

  if (!LittleFS.exists(NETWORK_FILE)) {
    File f = LittleFS.open(NETWORK_FILE, "w");
    if (f) f.close();
    return;
  }

  File f = LittleFS.open(NETWORK_FILE, "r");
  if (!f) return;

  while (f.available() && savedNetworks.size() < MAX_NETWORKS) {
    String line = f.readStringUntil('\n');
    line.trim();

    if (!line.length() || line.startsWith("#")) continue;

    int separator = line.indexOf('|');
    if (separator <= 0) continue;

    WiFiEntry entry;
    entry.ssid = line.substring(0, separator);
    entry.password = line.substring(separator + 1);

    entry.ssid.trim();
    entry.password.trim();

    if (entry.ssid.length()) {
      savedNetworks.push_back(entry);
    }
  }

  f.close();
}

void showWiFiNetworks() {
  Serial.println("\nSaved Wi-Fi networks:");

  if (!savedNetworks.size()) {
    Serial.println("  No networks saved.");
  }

  for (size_t i = 0; i < savedNetworks.size(); i++) {
    Serial.printf(
      "  %u. SSID: %s | Password: ********\n",
      (unsigned)(i + 1),
      savedNetworks[i].ssid.c_str()
    );
  }
}

void showDeviceConfig() {
  Serial.println("\nDeveloper-controlled configuration:");

  Serial.print("DEVICE_ID: ");
  Serial.println(DEVICE_ID);

  Serial.println("ESP_API_TOKEN: SET (hidden)");

  Serial.print("API URL: ");
  Serial.println(API_URL);
}

bool connectSavedWiFi() {
  if (!savedNetworks.size()) {
    Serial.println("No Wi-Fi network saved. Enter ADD WIFI.");
    return false;
  }

  if (!wifiStackInitialized) {
    WiFi.mode(WIFI_STA);
    delay(200);
    wifiStackInitialized = true;
  }

  for (size_t i = 0; i < savedNetworks.size(); i++) {
    Serial.printf(
      "Connecting to network %u: %s\n",
      (unsigned)(i + 1),
      savedNetworks[i].ssid.c_str()
    );

    WiFi.disconnect(false, false);
    delay(300);

    WiFi.begin(
      savedNetworks[i].ssid.c_str(),
      savedNetworks[i].password.c_str()
    );

    unsigned long started = millis();

    while (WiFi.status() != WL_CONNECTED &&
           millis() - started < 15000) {
      delay(250);
      Serial.print('.');
    }

    Serial.println();

    if (WiFi.status() == WL_CONNECTED) {
      Serial.println("Wi-Fi connected.");
      Serial.print("SSID: ");
      Serial.println(WiFi.SSID());
      Serial.print("ESP32 IP: ");
      Serial.println(WiFi.localIP());
      return true;
    }

    Serial.println("Connection failed; trying next network.");
  }

  Serial.println("Could not connect to any saved network.");
  return false;
}

// =====================================================
// 6. SERIAL COMMANDS
// =====================================================

void printHelp() {
  Serial.println("\n===== ESP32 PATIENT MONITOR COMMANDS =====");
  Serial.println("HELP                         - Show commands");
  Serial.println("SHOW WIFI                    - List saved SSIDs");
  Serial.println("ADD WIFI                     - Add network");
  Serial.println("EDIT WIFI N                  - Edit network N");
  Serial.println("DELETE WIFI N                - Delete network N");
  Serial.println("CLEAR WIFI                   - Delete all networks");
  Serial.println("CONNECT WIFI                 - Connect using saved networks");
  Serial.println("SHOW DEVICE                  - Show fixed device configuration");
  Serial.println("STATUS                       - Show Wi-Fi/API status");
  Serial.println("RESTART                      - Restart ESP32");
  Serial.println("Device ID and API token are fixed in firmware.");
  Serial.println("==========================================");
}

void processSerialCommand(String command) {
  command.trim();

  String upper = command;
  upper.toUpperCase();

  if (!command.length()) return;

  if (upper == "CANCEL" &&
      wifiInputState != WIFI_INPUT_NONE) {
    wifiInputState = WIFI_INPUT_NONE;
    pendingWiFiSSID = "";
    pendingWiFiEditIndex = -1;
    Serial.println("Wi-Fi entry cancelled.");
    return;
  }

  if (wifiInputState == WIFI_INPUT_SSID) {
    pendingWiFiSSID = command;

    if (!pendingWiFiSSID.length()) {
      Serial.println("SSID cannot be empty. Enter again or CANCEL.");
      return;
    }

    wifiInputState = WIFI_INPUT_PASSWORD;
    Serial.println("Enter Wi-Fi password, then press Enter:");
    return;
  }

  if (wifiInputState == WIFI_INPUT_PASSWORD) {
    String enteredPassword = command;

    if (pendingWiFiEditIndex >= 0) {
      savedNetworks[pendingWiFiEditIndex].ssid = pendingWiFiSSID;
      savedNetworks[pendingWiFiEditIndex].password = enteredPassword;
    } else {
      if (savedNetworks.size() >= MAX_NETWORKS) {
        Serial.println("Maximum 10 networks reached.");
        wifiInputState = WIFI_INPUT_NONE;
        pendingWiFiSSID = "";
        pendingWiFiEditIndex = -1;
        return;
      }

      WiFiEntry entry;
      entry.ssid = pendingWiFiSSID;
      entry.password = enteredPassword;
      savedNetworks.push_back(entry);
    }

    bool saved = saveNetworks();

    if (!saved) {
      Serial.println("ERROR: Could not save Wi-Fi credentials.");

      if (pendingWiFiEditIndex < 0 && savedNetworks.size()) {
        savedNetworks.pop_back();
      }
    } else {
      Serial.println(
        pendingWiFiEditIndex >= 0
          ? "Wi-Fi network updated successfully."
          : "Wi-Fi network saved successfully."
      );
    }

    wifiInputState = WIFI_INPUT_NONE;
    pendingWiFiSSID = "";
    pendingWiFiEditIndex = -1;
    return;
  }

  if (upper == "HELP") {
    printHelp();
    return;
  }

  if (upper == "SHOW WIFI") {
    showWiFiNetworks();
    return;
  }

  if (upper == "SHOW DEVICE") {
    showDeviceConfig();
    return;
  }

  if (upper == "STATUS") {
    Serial.println("\nStatus:");

    if (!wifiStackInitialized) {
      Serial.println("Wi-Fi: NOT INITIALIZED");
    } else {
      Serial.println(
        WiFi.status() == WL_CONNECTED
          ? "Wi-Fi: CONNECTED"
          : "Wi-Fi: DISCONNECTED"
      );

      if (WiFi.status() == WL_CONNECTED) {
        Serial.print("IP: ");
        Serial.println(WiFi.localIP());
      }
    }

    Serial.printf("Saved networks: %u\n",
                  (unsigned)savedNetworks.size());

    Serial.print("API upload interval: ");
    Serial.print(API_UPLOAD_INTERVAL_MS / 1000);
    Serial.println(" seconds");

    Serial.print("ECG sample rate: ");
    Serial.println(ECG_SAMPLE_RATE);

    Serial.print("ECG samples waiting: ");
    Serial.println(ecgUploadCount);

    showDeviceConfig();
    return;
  }

  if (upper == "CONNECT WIFI") {
    connectSavedWiFi();
    return;
  }

  if (upper == "CLEAR WIFI") {
    savedNetworks.clear();

    Serial.println(
      saveNetworks()
        ? "All saved Wi-Fi networks cleared."
        : "Could not save networks file."
    );

    if (wifiStackInitialized) {
      WiFi.disconnect(false, false);
    }
    return;
  }

  if (upper == "ADD WIFI") {
    if (!littlefsReady) {
      Serial.println("ERROR: LittleFS unavailable.");
      return;
    }

    if (savedNetworks.size() >= MAX_NETWORKS) {
      Serial.println("Maximum 10 networks reached.");
      return;
    }

    pendingWiFiSSID = "";
    pendingWiFiEditIndex = -1;
    wifiInputState = WIFI_INPUT_SSID;

    Serial.println("Enter Wi-Fi SSID, then press Enter:");
    return;
  }

  if (upper.startsWith("EDIT WIFI ")) {
    int n = command.substring(10).toInt();

    if (n < 1 || n > (int)savedNetworks.size()) {
      Serial.println("Invalid network number. Use SHOW WIFI.");
      return;
    }

    pendingWiFiSSID = "";
    pendingWiFiEditIndex = n - 1;
    wifiInputState = WIFI_INPUT_SSID;

    Serial.printf("Enter new SSID for network %d:\n", n);
    return;
  }

  if (upper.startsWith("DELETE WIFI ")) {
    int n = command.substring(12).toInt();

    if (n < 1 || n > (int)savedNetworks.size()) {
      Serial.println("Invalid network number.");
      return;
    }

    savedNetworks.erase(savedNetworks.begin() + n - 1);

    Serial.println(
      saveNetworks()
        ? "Wi-Fi network deleted."
        : "Save failed."
    );
    return;
  }

  if (upper.startsWith("SET DEVICE") ||
      upper.startsWith("SET TOKEN")) {
    Serial.println(
      "ACCESS DENIED: Device ID and API token are fixed in firmware."
    );
    return;
  }

  if (upper == "RESTART") {
    Serial.println("Restarting...");
    delay(500);
    ESP.restart();
  } else {
    Serial.println("Unknown command. Type HELP.");
  }
}

void handleSerialCommands() {
  static String input;

  while (Serial.available()) {
    char c = (char)Serial.read();

    if (c == '\r' || c == '\n') {
      if (input.length()) {
        processSerialCommand(input);
        input = "";
      }
    } else if (input.length() < 220) {
      input += c;
    }
  }
}

// =====================================================
// 7. SENSOR PIN SETTINGS AND REAL SENSOR VARIABLES
// =====================================================

#if SIMULATION_MODE == 0

#define DHT_PIN 4
#define DHT_TYPE DHT11
#define DS18B20_PIN 5
#define I2C_SDA 21
#define I2C_SCL 22
#define ECG_PIN 34

DHT dhtSensor(DHT_PIN, DHT_TYPE);
OneWire oneWire(DS18B20_PIN);
DallasTemperature dsSensor(&oneWire);
PulseOximeter pox;

bool max30100Ready = false;

bool dhtOK = false;
bool dsOK = false;
bool pulseOK = false;

float lastValidBPM = 0;
float lastValidSpO2 = 0;

unsigned long dsRequestMillis = 0;
bool dsConversionPending = false;

const unsigned long ECG_INTERVAL_US = 4000;
unsigned long previousECGMicros = 0;

float ecgBaseline = 0;
bool baselineStarted = false;

int16_t ecgDisplayBuffer[ECG_DISPLAY_BUFFER_SIZE];

int ecgDisplayHead = 0;
int ecgDisplayTail = 0;

#endif

// =====================================================
// 8. DISPLAY VALUES
// =====================================================

float temperature = 28.5;
float humidity = 65.0;
float BPM = 76.0;
float SpO2 = 98.0;
float bodytemperature = 36.8;

#if SIMULATION_MODE == 1
bool dhtOK = true;
bool dsOK = true;
bool pulseOK = true;
#endif

unsigned long previousSensorMillis = 0;

// =====================================================
// 9. FIXED ABNORMAL SIMULATED VALUES FOR ALERT TEST
// =====================================================

void updateSimulatedValues() {

  // Room readings remain normal.
  temperature = 28.5;
  humidity = 65.0;

  // Abnormal readings for P1001 alert testing.
  BPM = 140.0;
  SpO2 = 85.0;
  bodytemperature = 39.0;

  dhtOK = true;
  dsOK = true;
  pulseOK = true;
}

// =====================================================
// 10. ECG UPLOAD BUFFER
// =====================================================

void addECGUploadSample(int16_t sample) {
  ecgUploadBuffer[ecgUploadHead] = sample;

  ecgUploadHead =
    (ecgUploadHead + 1) % ECG_UPLOAD_BUFFER_SIZE;

  if (ecgUploadCount < ECG_UPLOAD_BUFFER_SIZE) {
    ecgUploadCount++;
  }
}

// =====================================================
// 11. SIMULATED ECG GENERATOR
// =====================================================

float simulatedECGValue(float phase) {
  float p =
    0.12 * expf(-powf((phase - 0.18) / 0.035, 2));

  float q =
    -0.15 * expf(-powf((phase - 0.38) / 0.012, 2));

  float r =
    1.00 * expf(-powf((phase - 0.40) / 0.010, 2));

  float s =
    -0.25 * expf(-powf((phase - 0.425) / 0.016, 2));

  float tw =
    0.30 * expf(-powf((phase - 0.68) / 0.070, 2));

  return p + q + r + s + tw;
}

void generateSimulatedECG(int16_t* output, int count) {
  float bpm = BPM;

  if (bpm < 40 || bpm > 180) {
    bpm = 76;
  }

  static float elapsedSeconds = 0;

  for (int i = 0; i < count; i++) {
    float phase =
      fmodf(elapsedSeconds * bpm / 60.0, 1.0);

    float signal = simulatedECGValue(phase);

    float noise = random(-15, 16) / 1000.0;
    signal += noise;

    if (signal > 1.0) signal = 1.0;
    if (signal < -1.0) signal = -1.0;

    output[i] = (int16_t)(signal * 1000.0);

    elapsedSeconds += 1.0 / ECG_SAMPLE_RATE;
  }
}

// =====================================================
// 12. REAL SENSOR INITIALIZATION
// =====================================================

#if SIMULATION_MODE == 0

void initializeSensors() {
  Serial.println("Initializing sensors...");

  dhtSensor.begin();

  dsSensor.begin();
  dsSensor.setResolution(10);
  dsSensor.setWaitForConversion(false);
  dsSensor.requestTemperatures();

  dsRequestMillis = millis();
  dsConversionPending = true;

  Wire.begin(I2C_SDA, I2C_SCL);

  if (pox.begin()) {
    max30100Ready = true;
    pox.setIRLedCurrent(MAX30100_LED_CURR_7_6MA);
    Serial.println("MAX30100 detected.");
  } else {
    max30100Ready = false;
    Serial.println("MAX30100 NOT DETECTED.");
  }

  analogReadResolution(12);
  analogSetPinAttenuation(ECG_PIN, ADC_11db);

  Serial.println("Sensor initialization completed.");
}

// =====================================================
// 13. READ REAL SENSORS
// =====================================================

void updateRealSensors() {
  unsigned long now = millis();

  if (now - previousSensorMillis >= 2000) {
    previousSensorMillis = now;

    float newHumidity = dhtSensor.readHumidity();
    float newTemperature = dhtSensor.readTemperature();

    if (!isnan(newHumidity) && !isnan(newTemperature)) {
      humidity = newHumidity;
      temperature = newTemperature;
      dhtOK = true;
    } else {
      dhtOK = false;
    }
  }

  if (dsConversionPending &&
      now - dsRequestMillis >= 200) {

    float newBodyTemperature =
      dsSensor.getTempCByIndex(0);

    if (newBodyTemperature != DEVICE_DISCONNECTED_C &&
        newBodyTemperature >= -55 &&
        newBodyTemperature <= 125) {

      bodytemperature = newBodyTemperature;
      dsOK = true;

    } else {
      dsOK = false;
    }

    dsSensor.requestTemperatures();
    dsRequestMillis = now;
    dsConversionPending = true;
  }

  if (max30100Ready) {
    float newBPM = pox.getHeartRate();
    float newSpO2 = pox.getSpO2();

    if (newBPM > 30 && newBPM < 220 &&
        newSpO2 >= 70 && newSpO2 <= 100) {

      lastValidBPM = newBPM;
      lastValidSpO2 = newSpO2;
      pulseOK = true;

    } else {
      pulseOK = false;
    }
  } else {
    pulseOK = false;
  }

  BPM = lastValidBPM;
  SpO2 = lastValidSpO2;
}

// =====================================================
// 14. REAL ECG ACQUISITION
// =====================================================

void acquireECG() {
  unsigned long now = micros();

  if (now - previousECGMicros >= ECG_INTERVAL_US) {
    previousECGMicros = now;

    int rawValue = analogRead(ECG_PIN);

    if (!baselineStarted) {
      ecgBaseline = rawValue;
      baselineStarted = true;
    }

    ecgBaseline =
      ecgBaseline * 0.995 + rawValue * 0.005;

    float signal = rawValue - ecgBaseline;
    signal = signal / 500.0;

    if (signal > 1.0) signal = 1.0;
    if (signal < -1.0) signal = -1.0;

    int16_t sample = (int16_t)(signal * 1000);

    int nextHead =
      (ecgDisplayHead + 1) % ECG_DISPLAY_BUFFER_SIZE;

    if (nextHead != ecgDisplayTail) {
      ecgDisplayBuffer[ecgDisplayHead] = sample;
      ecgDisplayHead = nextHead;
    }

    addECGUploadSample(sample);
  }
}

#endif

// =====================================================
// 15. APPEND ECG TO JSON
// =====================================================

void appendECGJSON(
  String &json,
  const int16_t* samples,
  int count
) {
  json += ",\"sample_rate\":";
  json += String(ECG_SAMPLE_RATE);

  json += ",\"ecg_samples\":[";

  for (int i = 0; i < count; i++) {
    if (i > 0) json += ",";
    json += String(samples[i]);
  }

  json += "]";
}

// =====================================================
// 16. SEND MONITORING + ECG READINGS TO RENDER PHP API
// =====================================================

void uploadMonitoringReadings() {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[API] Wi-Fi disconnected. Upload skipped.");
    return;
  }

#if SIMULATION_MODE == 0
  if (!dhtOK || !dsOK || !pulseOK) {
    Serial.println(
      "[API] Upload skipped: one or more real sensor readings are invalid."
    );
    return;
  }
#endif

  String modeText =
    (SIMULATION_MODE == 1) ? "SIMULATION" : "REAL";

  int16_t simulatedSamples[ECG_UPLOAD_BUFFER_SIZE];

  int16_t* samplesToSend = nullptr;
  int sampleCountToSend = 0;

#if SIMULATION_MODE == 1

  sampleCountToSend = ECG_UPLOAD_BUFFER_SIZE;

  generateSimulatedECG(
    simulatedSamples,
    sampleCountToSend
  );

  samplesToSend = simulatedSamples;

#else

  sampleCountToSend = ecgUploadCount;

  if (sampleCountToSend > 0) {
    samplesToSend = new int16_t[sampleCountToSend];

    int oldestIndex =
      (ecgUploadHead - ecgUploadCount +
       ECG_UPLOAD_BUFFER_SIZE) %
      ECG_UPLOAD_BUFFER_SIZE;

    for (int i = 0; i < sampleCountToSend; i++) {
      int index =
        (oldestIndex + i) % ECG_UPLOAD_BUFFER_SIZE;

      samplesToSend[i] = ecgUploadBuffer[index];
    }
  }

#endif

  String json;
  json.reserve(18000);

  json = "{";

  json += "\"device_id\":\"";
  json += DEVICE_ID;
  json += "\",";

  json += "\"room_temperature\":";
  json += String(temperature, 1);
  json += ",";

  json += "\"humidity\":";
  json += String(humidity, 1);
  json += ",";

  json += "\"bpm\":";
  json += String(BPM, 1);
  json += ",";

  json += "\"spo2\":";
  json += String(SpO2, 1);
  json += ",";

  json += "\"body_temperature\":";
  json += String(bodytemperature, 1);
  json += ",";

  json += "\"mode\":\"";
  json += modeText;
  json += "\",";

  json += "\"timezone\":\"Asia/Kolkata\"";

  if (sampleCountToSend > 0) {
    appendECGJSON(json, samplesToSend, sampleCountToSend);
  }

  json += "}";

#if SIMULATION_MODE == 0
  delete[] samplesToSend;
#endif

  Serial.println();
  Serial.println("======================================");
  Serial.println("[API] Uploading monitoring + ECG data");

  Serial.print("[API] Device ID: ");
  Serial.println(DEVICE_ID);

  Serial.print("[API] Mode: ");
  Serial.println(modeText);

  Serial.print("[API] BPM: ");
  Serial.println(BPM);

  Serial.print("[API] SpO2: ");
  Serial.println(SpO2);

  Serial.print("[API] Body temperature: ");
  Serial.println(bodytemperature);

  Serial.print("[API] ECG samples: ");
  Serial.println(sampleCountToSend);

  Serial.print("[API] ECG sample rate: ");
  Serial.println(ECG_SAMPLE_RATE);

  Serial.print("[API] JSON size: ");
  Serial.print(json.length());
  Serial.println(" bytes");

  Serial.print("[API] URL: ");
  Serial.println(API_URL);

  // ---------------------------------------------------
  // HTTPS CONNECTION TO RENDER
  // ---------------------------------------------------

  WiFiClientSecure client;

  // TESTING ONLY:
  // Uses encrypted HTTPS but does not verify the server certificate.
  // For production, configure a trusted CA certificate instead.
  client.setInsecure();

  HTTPClient http;

  http.setTimeout(20000);

  if (!http.begin(client, API_URL)) {
    Serial.println("[API] ERROR: Could not start HTTPS connection.");
    Serial.println("Check Render URL and ESP32 network connection.");
    Serial.println("======================================");
    return;
  }

  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-API-TOKEN", ESP_API_TOKEN);

  int httpCode = http.POST(json);

  Serial.print("[API] HTTP response code: ");
  Serial.println(httpCode);

  bool uploadSucceeded = false;
  bool ecgConfirmed = false;

  String response = "";

  if (httpCode > 0) {
    response = http.getString();

    Serial.println("[API] Server response:");
    Serial.println(response);

    if (httpCode == 200 &&
        response.indexOf("\"success\":true") >= 0) {

      uploadSucceeded = true;

      if (sampleCountToSend > 0 &&
          response.indexOf("\"ecg_saved\":true") >= 0) {

        ecgConfirmed = true;
        Serial.println("[API] ECG SAVED SUCCESSFULLY");

      } else if (sampleCountToSend > 0) {

        Serial.println(
          "[API] WARNING: ECG save confirmation missing."
        );
      }

      Serial.println("[API] MONITORING UPLOAD SUCCESSFUL");

    } else if (httpCode == 401) {

      Serial.println("[API] REJECTED: Invalid API token.");

    } else if (httpCode == 403) {

      Serial.println(
        "[API] REJECTED: Device registration, enable status or access dates."
      );

    } else if (httpCode == 400) {

      Serial.println(
        "[API] REJECTED: Check JSON fields and values."
      );

    } else if (httpCode >= 500) {

      Serial.println(
        "[API] SERVER ERROR: Check PHP and MySQL error logs."
      );

    } else {

      Serial.println("[API] Upload was not accepted.");
    }

  } else {

    Serial.print("[API] HTTPS connection failed: ");
    Serial.println(http.errorToString(httpCode));

    Serial.println(
      "[API] Check Wi-Fi, Render server availability and HTTPS connection."
    );
  }

  http.end();

#if SIMULATION_MODE == 0

  if (uploadSucceeded && ecgConfirmed) {
    ecgUploadHead = 0;
    ecgUploadCount = 0;
  }

#endif

  Serial.println("======================================");
}

// =====================================================
// 17. SENSOR VALUES JSON ENDPOINT
// =====================================================

void handleValues() {
  String json = "{";

  json += "\"temperature\":" + String(temperature, 1) + ",";
  json += "\"humidity\":" + String(humidity, 1) + ",";
  json += "\"BPM\":" + String(BPM, 0) + ",";
  json += "\"SpO2\":" + String(SpO2, 0) + ",";
  json += "\"bodytemperature\":" +
          String(bodytemperature, 1) + ",";

  json += "\"dhtOK\":";
  json += (dhtOK ? "true" : "false");
  json += ",";

  json += "\"dsOK\":";
  json += (dsOK ? "true" : "false");
  json += ",";

  json += "\"pulseOK\":";
  json += (pulseOK ? "true" : "false");

  json += "}";

  server.send(
    200,
    "application/json; charset=utf-8",
    json
  );
}

// =====================================================
// 18. ECG DATA ENDPOINT FOR WEBPAGE
// =====================================================

void handleECG() {
  String json = "[";

#if SIMULATION_MODE == 0

  bool first = true;
  int count = 0;

  while (ecgDisplayTail != ecgDisplayHead && count < 80) {
    int16_t value = ecgDisplayBuffer[ecgDisplayTail];

    ecgDisplayTail =
      (ecgDisplayTail + 1) % ECG_DISPLAY_BUFFER_SIZE;

    if (!first) json += ",";

    json += String(value);

    first = false;
    count++;
  }

#endif

  json += "]";

  server.send(
    200,
    "application/json; charset=utf-8",
    json
  );
}

// =====================================================
// 19. HTML WEBPAGE
// =====================================================

String SendHTML() {
  String ptr;
  ptr.reserve(14000);

  ptr += R"rawliteral(
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ESP32 Health Monitoring System</title>

<style>
body {
  font-family: Arial, Helvetica, sans-serif;
  text-align: center;
  margin: 0;
  color: #333333;
  background: #ffffff;
}
h1 {
  margin: 25px 5px;
  font-size: 26px;
}
.demo {
  background: #fff3cd;
  color: #856404;
  padding: 14px;
  font-weight: bold;
  font-size: 15px;
}
.container {
  display: table;
  margin: 0 auto;
}
.data {
  display: table-row;
}
.side-by-side {
  display: table-cell;
  vertical-align: middle;
  padding: 10px 5px;
}
.icon {
  width: 95px;
  text-align: center;
  font-size: 12px;
  font-weight: bold;
  color: #666666;
}
.text {
  font-weight: bold;
  font-size: 17px;
  text-align: left;
  width: 190px;
}
.reading {
  font-size: 36px;
  font-weight: 300;
  white-space: nowrap;
}
.temperature .reading,
.bodytemperature .reading {
  color: #F29C21;
}
.humidity .reading {
  color: #3B97D3;
}
.BPM .reading {
  color: #FF0000;
}
.SpO2 .reading {
  color: #955BA5;
}
.unit {
  font-size: 14px;
  vertical-align: top;
}
.ecg-title {
  margin-top: 35px;
  font-size: 24px;
}
.ecg-panel {
  width: 95%;
  max-width: 950px;
  margin: 15px auto;
  background: #08110b;
  border: 2px solid #28502d;
  border-radius: 8px;
  padding: 5px;
  box-sizing: border-box;
}
#ecgCanvas {
  width: 100%;
  height: auto;
  display: block;
}
.status {
  font-size: 14px;
  margin: 10px;
}
.good { color: green; }
.bad { color: red; }
footer {
  font-size: 13px;
  color: #777777;
  margin: 25px;
}
@media(max-width:500px) {
  .icon {
    width: 75px;
    font-size: 10px;
  }
  .text {
    width: 140px;
    font-size: 14px;
  }
  .reading {
    font-size: 28px;
  }
}
</style>
</head>

<body>

<div class="demo" id="modeText"></div>
<h1>ESP32 Health Monitoring System</h1>

<div class="container">

<div class="data temperature">
<div class="side-by-side icon">TEMP</div>
<div class="side-by-side text">Room Temperature</div>
<div class="side-by-side reading">
<span id="temperature">--</span>
<span class="unit">&deg;C</span>
</div>
</div>

<div class="data humidity">
<div class="side-by-side icon">HUMIDITY</div>
<div class="side-by-side text">Humidity</div>
<div class="side-by-side reading">
<span id="humidity">--</span>
<span class="unit">%</span>
</div>
</div>

<div class="data BPM">
<div class="side-by-side icon">HEART</div>
<div class="side-by-side text">Heart Rate</div>
<div class="side-by-side reading">
<span id="BPM">--</span>
<span class="unit">BPM</span>
</div>
</div>

<div class="data SpO2">
<div class="side-by-side icon">SpO2</div>
<div class="side-by-side text">SpO2</div>
<div class="side-by-side reading">
<span id="SpO2">--</span>
<span class="unit">%</span>
</div>
</div>

<div class="data bodytemperature">
<div class="side-by-side icon">BODY TEMP</div>
<div class="side-by-side text">Body Temperature</div>
<div class="side-by-side reading">
<span id="bodytemperature">--</span>
<span class="unit">&deg;C</span>
</div>
</div>

</div>

<h2 class="ecg-title">ECG Waveform</h2>

<div class="ecg-panel">
<canvas id="ecgCanvas" width="900" height="240"></canvas>
</div>

<div id="ecgStatus" class="status"></div>
<div id="sensorStatus" class="status"></div>

<p>Educational prototype - not for clinical use.</p>

<footer>ESP32 Patient Monitoring Demonstration</footer>

<script>

const SIMULATION = )rawliteral";

#if SIMULATION_MODE == 1
  ptr += "true";
#else
  ptr += "false";
#endif

  ptr += R"rawliteral(;

document.getElementById("modeText").textContent =
  SIMULATION
  ? "SIMULATION MODE - FIXED ABNORMAL ALERT TEST"
  : "REAL SENSOR MODE";

async function updateReadings() {
  try {
    const response = await fetch("/values");
    const data = await response.json();

    function show(id, value, valid, digits) {
      const element = document.getElementById(id);
      element.textContent =
        valid ? Number(value).toFixed(digits) : "--";
    }

    show("temperature", data.temperature, data.dhtOK, 1);
    show("humidity", data.humidity, data.dhtOK, 1);
    show("BPM", data.BPM, data.pulseOK, 0);
    show("SpO2", data.SpO2, data.pulseOK, 0);

    show(
      "bodytemperature",
      data.bodytemperature,
      data.dsOK,
      1
    );

    if (!SIMULATION) {
      document.getElementById("sensorStatus").textContent =
        "DHT11: " + (data.dhtOK ? "OK" : "NOT DETECTED") +
        " | DS18B20: " + (data.dsOK ? "OK" : "NOT DETECTED") +
        " | MAX30100: " +
        (data.pulseOK ? "READING" : "NO VALID READING");
    }

  } catch(error) {
    console.log("Sensor update error", error);
  }
}

updateReadings();
setInterval(updateReadings, 1000);

const canvas = document.getElementById("ecgCanvas");
const ctx = canvas.getContext("2d");

const W = canvas.width;
const H = canvas.height;

const samples = [];
const MAX_SAMPLES = W;

function drawGrid() {
  ctx.fillStyle = "#08110b";
  ctx.fillRect(0, 0, W, H);

  for (let x = 0; x <= W; x += 25) {
    ctx.beginPath();

    ctx.strokeStyle =
      (x % 125 === 0) ? "#28502d" : "#18301c";

    ctx.lineWidth = 1;
    ctx.moveTo(x, 0);
    ctx.lineTo(x, H);
    ctx.stroke();
  }

  for (let y = 0; y <= H; y += 25) {
    ctx.beginPath();

    ctx.strokeStyle =
      (y % 125 === 0) ? "#28502d" : "#18301c";

    ctx.lineWidth = 1;
    ctx.moveTo(0, y);
    ctx.lineTo(W, y);
    ctx.stroke();
  }
}

function simulatedECG(t) {
  const bpm =
    Number(document.getElementById("BPM").textContent) || 76;

  const phase = (t * bpm / 60) % 1.0;

  const p =
    0.12 * Math.exp(-Math.pow((phase - 0.18) / 0.035, 2));

  const q =
    -0.15 * Math.exp(-Math.pow((phase - 0.38) / 0.012, 2));

  const r =
    1.00 * Math.exp(-Math.pow((phase - 0.40) / 0.010, 2));

  const s =
    -0.25 * Math.exp(-Math.pow((phase - 0.425) / 0.016, 2));

  const tw =
    0.30 * Math.exp(-Math.pow((phase - 0.68) / 0.070, 2));

  return p + q + r + s + tw;
}

function addSample(value) {
  samples.push(value);

  while (samples.length > MAX_SAMPLES) {
    samples.shift();
  }
}

function drawECG() {
  drawGrid();

  ctx.beginPath();
  ctx.strokeStyle = "#00ff66";
  ctx.lineWidth = 2;

  for (let i = 0; i < samples.length; i++) {
    const x = i;
    const y = H / 2 - samples[i] * 75;

    if (i === 0) {
      ctx.moveTo(x, y);
    } else {
      ctx.lineTo(x, y);
    }
  }

  ctx.stroke();
}

if (SIMULATION) {
  document.getElementById("ecgStatus").textContent =
    "SIMULATED ECG - NOT A REAL ECG RECORDING";

  setInterval(function() {
    const t = performance.now() / 1000;
    addSample(simulatedECG(t));
  }, 8);
}

if (!SIMULATION) {
  document.getElementById("ecgStatus").textContent =
    "AD8232 ECG SIGNAL - EXPERIMENTAL";

  async function readECG() {
    try {
      const response = await fetch("/ecg");
      const batch = await response.json();

      for (const value of batch) {
        addSample(Number(value) / 1000);
      }

    } catch(error) {
      console.log("ECG acquisition error", error);
    }
  }

  setInterval(readECG, 40);
}

function animateECG() {
  drawECG();
  requestAnimationFrame(animateECG);
}

animateECG();

</script>
</body>
</html>
)rawliteral";

  return ptr;
}

// =====================================================
// 20. WEB SERVER HANDLERS
// =====================================================

void handleRoot() {
  server.send(
    200,
    "text/html; charset=utf-8",
    SendHTML()
  );
}

// =====================================================
// 21. SETUP
// =====================================================

void setup() {
  Serial.begin(115200);
  randomSeed(esp_random());

  Serial.println();
  Serial.println("ESP32 HEALTH MONITORING SYSTEM");
  Serial.println("Serial Monitor: 115200 baud, Newline ending");

  littlefsReady = LittleFS.begin(false);

  if (!littlefsReady) {
    Serial.println(
      "ERROR: LittleFS mount failed. Configuration unavailable."
    );
  } else {
    Serial.println("LittleFS mounted.");
    loadNetworks();
    showDeviceConfig();
    showWiFiNetworks();
  }

  printHelp();

#if SIMULATION_MODE == 1
  Serial.println("SIMULATION MODE ENABLED");
  Serial.println("FIXED ABNORMAL ALERT TEST VALUES ENABLED");
  Serial.println("BPM = 140");
  Serial.println("SpO2 = 85");
  Serial.println("Body temperature = 39.0");
  Serial.println("ESP32 simulated ECG upload enabled.");
  updateSimulatedValues();
#else
  Serial.println("REAL SENSOR MODE ENABLED");
  Serial.println("AD8232 ECG upload enabled.");
  initializeSensors();
#endif

  WiFi.mode(WIFI_STA);
  delay(200);
  wifiStackInitialized = true;

  if (littlefsReady && savedNetworks.size()) {
    connectSavedWiFi();
  } else {
    Serial.println(
      "No Wi-Fi network saved. Enter ADD WIFI to configure one."
    );
  }

  if (WiFi.status() != WL_CONNECTED) {
    Serial.println(
      "Webpage and API uploads require a Wi-Fi connection."
    );
  }

  server.on("/", handleRoot);
  server.on("/values", handleValues);
  server.on("/ecg", handleECG);

  server.begin();

  Serial.println("Web server started.");

  if (WiFi.status() == WL_CONNECTED) {
    Serial.print("Open ESP32 webpage: http://");
    Serial.println(WiFi.localIP());
  }

  Serial.print("PHP API destination: ");
  Serial.println(API_URL);

  Serial.println("Monitoring upload interval: 5 seconds");
  Serial.println("ECG sample rate: 250 samples/second");
  Serial.println("ECG upload batch: up to 1250 samples");
}

// =====================================================
// 22. MAIN LOOP
// =====================================================

void loop() {
  handleSerialCommands();

  if (wifiStackInitialized &&
      WiFi.status() == WL_CONNECTED) {
    server.handleClient();
  }

#if SIMULATION_MODE == 1

  if (millis() - previousSensorMillis >= 1000) {
    previousSensorMillis = millis();

    // Keep the same fixed abnormal values.
    updateSimulatedValues();
  }

#else

  if (max30100Ready) {
    pox.update();
  }

  acquireECG();
  updateRealSensors();

#endif

  unsigned long now = millis();

  if (firstUploadAttempt ||
      now - previousUploadMillis >= API_UPLOAD_INTERVAL_MS) {

    firstUploadAttempt = false;
    previousUploadMillis = now;

    uploadMonitoringReadings();
  }
}
