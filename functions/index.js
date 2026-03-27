const functions = require("firebase-functions");
const admin = require("firebase-admin");
const cors = require("cors")({ origin: true });
const fetch = require("node-fetch");
const sharp = require("sharp");
const archiver = require("archiver");

admin.initializeApp();
const db = admin.firestore();

// --- 設定 ---
const API_FLASH_KEY = "91b5039ab597473f90ac4ad1de56202f";
const GOOGLE_CLOUD_VISION_API_KEY = "AIzaSyASFBMI_Sgt5TsW9j4d_mifd2RR4U3P5cw";
const MONTHLY_LIMIT = 100;

// 医療広告ガイドライン関連のNGワードリスト
const NG_WORDS = [
  "絶対治る", "必ず治る", "100%", "完治する", "日本一", "No.1", "ナンバーワン",
  "最高", "最先端", "最新", "完璧", "当院だけ", "他院より", "類を見ない",
  "絶対安全", "副作用なし", "痛みなし", "無痛", "失敗しない", "最善"
];

// --- ユーティリティ関数 ---

/**
 * 現在の月の使用回数を取得・更新する（Firestoreベース）
 */
async function getUsageCount() {
  const currentMonth = new Date().toISOString().slice(0, 7); // "YYYY-MM"
  const docRef = db.collection("usage").doc("monthly");
  const doc = await docRef.get();

  if (doc.exists && doc.data().month === currentMonth) {
    return { count: doc.data().count || 0, month: currentMonth };
  }
  return { count: 0, month: currentMonth };
}

async function incrementUsageCount(numCaptures) {
  const currentMonth = new Date().toISOString().slice(0, 7);
  const docRef = db.collection("usage").doc("monthly");

  await db.runTransaction(async (transaction) => {
    const doc = await transaction.get(docRef);
    let currentCount = 0;
    if (doc.exists && doc.data().month === currentMonth) {
      currentCount = doc.data().count || 0;
    }
    transaction.set(docRef, {
      month: currentMonth,
      count: currentCount + numCaptures,
    });
  });
}

/**
 * 画像データをGoogle Cloud Vision APIに送信し、NGワードが含まれているかチェックする
 */
async function checkImageForNgWords(imageBuffer) {
  if (!GOOGLE_CLOUD_VISION_API_KEY || GOOGLE_CLOUD_VISION_API_KEY === "YOUR_GOOGLE_CLOUD_VISION_API_KEY") {
    throw new Error("Google Cloud Vision API key is not set.");
  }

  const base64Image = imageBuffer.toString("base64");

  const payload = {
    requests: [
      {
        image: { content: base64Image },
        features: [
          { type: "DOCUMENT_TEXT_DETECTION" },
          { type: "FACE_DETECTION" },
        ],
      },
    ],
  };

  const url = `https://vision.googleapis.com/v1/images:annotate?key=${GOOGLE_CLOUD_VISION_API_KEY}`;
  const response = await fetch(url, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });

  if (response.ok) {
    const result = await response.json();

    // API内部エラーチェック
    if (result.responses && result.responses[0] && result.responses[0].error) {
      const errorMsg = result.responses[0].error.message || "Unknown API Error";
      throw new Error(`Vision API Internal Error: ${errorMsg}`);
    }

    // 1. NGワードチェック（テキスト検出）
    const fullTextAnnotation = result.responses?.[0]?.fullTextAnnotation;
    if (fullTextAnnotation && fullTextAnnotation.text) {
      const normalizedText = fullTextAnnotation.text.replace(/[\r\n\s　]/g, "");

      for (const word of NG_WORDS) {
        if (normalizedText.includes(word)) {
          console.log(`NG Word Detected: ${word}`);
          let ngVertices = null;
          const textAnnotations = result.responses[0].textAnnotations;
          if (textAnnotations) {
            for (let i = 1; i < textAnnotations.length; i++) {
              const desc = textAnnotations[i].description;
              if (desc.includes(word) || word.includes(desc)) {
                if (textAnnotations[i].boundingPoly && textAnnotations[i].boundingPoly.vertices) {
                  ngVertices = textAnnotations[i].boundingPoly.vertices;
                  break;
                }
              }
            }
          }
          return { reason: `NGワード「${word}」`, vertices: ngVertices };
        }
      }
    }

    // 2. 顔検出
    const faceAnnotations = result.responses?.[0]?.faceAnnotations;
    if (faceAnnotations) {
      for (const face of faceAnnotations) {
        if (face.detectionConfidence && face.detectionConfidence > 0.5) {
          if (face.boundingPoly && face.boundingPoly.vertices) {
            console.log(`Face Detected with confidence: ${face.detectionConfidence}`);
            return { reason: "人物の顔（AI視覚判定）", vertices: face.boundingPoly.vertices };
          }
        }
      }
    }
  } else {
    const errorBody = await response.text();
    let errorMsg = `HTTP ${response.status}`;
    try {
      const errorJson = JSON.parse(errorBody);
      if (errorJson.error && errorJson.error.message) {
        errorMsg += ` - ${errorJson.error.message}`;
      }
    } catch (e) { /* ignore */ }
    console.error(`Vision API Error: ${errorMsg}`);
    throw new Error(`Vision API 実行エラー: ${errorMsg}`);
  }

  return false; // NGワードなし
}

/**
 * WebP画像をクロップする（sharpライブラリ使用）
 */
async function cropWebpImage(imageBuffer, targetWidth, targetHeight, startY = 0) {
  try {
    const metadata = await sharp(imageBuffer).metadata();
    const sourceWidth = metadata.width;
    const sourceHeight = metadata.height;

    if (sourceHeight <= targetHeight && startY === 0) {
      return imageBuffer;
    }

    if (startY >= sourceHeight) {
      startY = Math.max(0, sourceHeight - targetHeight);
    }

    const actualCropHeight = Math.min(targetHeight, sourceHeight - startY);

    // クロップ後、ターゲットサイズのキャンバスに配置
    const cropped = await sharp(imageBuffer)
      .extract({
        left: 0,
        top: startY,
        width: sourceWidth,
        height: actualCropHeight,
      })
      .webp()
      .toBuffer();

    return cropped;
  } catch (e) {
    console.error("Image crop error:", e.message);
    return imageBuffer;
  }
}

/**
 * 画像に赤枠を描画する（sharpライブラリ使用）
 */
async function drawRedBox(imageBuffer, vertices) {
  try {
    const metadata = await sharp(imageBuffer).metadata();

    if (!vertices || vertices.length < 3) {
      return imageBuffer;
    }

    // 頂点から矩形を計算
    const xs = vertices.map((v) => v.x || 0);
    const ys = vertices.map((v) => v.y || 0);
    const minX = Math.max(0, Math.min(...xs));
    const minY = Math.max(0, Math.min(...ys));
    const maxX = Math.min(metadata.width, Math.max(...xs));
    const maxY = Math.min(metadata.height, Math.max(...ys));

    const thickness = 5;

    // SVGで赤枠を描画
    const svgOverlay = `
      <svg width="${metadata.width}" height="${metadata.height}">
        <rect x="${minX}" y="${minY}" width="${maxX - minX}" height="${maxY - minY}"
              fill="none" stroke="red" stroke-width="${thickness}" />
      </svg>
    `;

    const result = await sharp(imageBuffer)
      .composite([{
        input: Buffer.from(svgOverlay),
        top: 0,
        left: 0,
      }])
      .webp()
      .toBuffer();

    return result;
  } catch (e) {
    console.error("DrawRedBox error:", e.message);
    return imageBuffer;
  }
}


// ===========================
// Cloud Functions エンドポイント
// ===========================

/**
 * GET /api/ngwords - NGワード一覧を返す
 */
exports.getNgWords = functions.https.onRequest((req, res) => {
  cors(req, res, () => {
    res.json({ ngWords: NG_WORDS });
  });
});

/**
 * GET /api/usage - 使用状況を返す
 */
exports.getUsage = functions.https.onRequest((req, res) => {
  cors(req, res, async () => {
    try {
      const usage = await getUsageCount();
      res.json({
        count: usage.count,
        limit: MONTHLY_LIMIT,
        message: `今月の作成枚数 (目安): ${usage.count} / ${MONTHLY_LIMIT} 枚`,
      });
    } catch (e) {
      console.error("getUsage error:", e);
      res.status(500).json({ error: "使用状況の取得に失敗しました" });
    }
  });
});

/**
 * POST /api/capture - キャプチャ取得 & OCR判定
 */
exports.capture = functions
  .runWith({ timeoutSeconds: 300, memory: "1GB" })
  .https.onRequest((req, res) => {
    cors(req, res, async () => {
      if (req.method !== "POST") {
        return res.status(405).json({ error: "Method not allowed" });
      }

      const input = req.body;

      // --- ZIP生成リクエスト ---
      if (input.action === "zip") {
        try {
          const images = input.images || [];
          if (images.length === 0) {
            return res.status(400).json({ error: "画像データがありません。" });
          }

          // archiver でメモリ上にZIP生成
          const archiverInstance = archiver("zip", { zlib: { level: 5 } });
          const chunks = [];

          archiverInstance.on("data", (chunk) => chunks.push(chunk));

          await new Promise((resolve, reject) => {
            archiverInstance.on("end", resolve);
            archiverInstance.on("error", reject);

            for (const img of images) {
              const base64 = img.data.replace(/^data:image\/\w+;base64,/i, "");
              const imgBuffer = Buffer.from(base64, "base64");
              archiverInstance.append(imgBuffer, { name: img.name });
            }
            archiverInstance.finalize();
          });

          const zipBuffer = Buffer.concat(chunks);
          return res.json({
            success: true,
            zip_base64: zipBuffer.toString("base64"),
          });
        } catch (e) {
          console.error("ZIP error:", e);
          return res.status(500).json({ error: "Zipファイルの作成に失敗しました。" });
        }
      }

      // --- キャプチャ生成リクエスト ---
      const urls = input.urls || [];
      const width = parseInt(input.width) || 1280;
      const height = parseInt(input.height) || 550;

      if (urls.length === 0) {
        return res.status(400).json({ error: "URLが指定されていません" });
      }

      // 上限チェック
      try {
        const usage = await getUsageCount();
        if (usage.count + urls.length > MONTHLY_LIMIT) {
          return res.status(429).json({
            error: `API上限に達します。今月の残り作成可能枚数は ${MONTHLY_LIMIT - usage.count} 枚です。`,
          });
        }
      } catch (e) {
        console.error("Usage check error:", e);
        // 使用量チェック失敗時は処理を続行
      }

      // --- 画像生成処理 ---
      let successfulCaptures = 0;
      const finalResults = [];
      const processLogs = [];

      for (let index = 0; index < urls.length; index++) {
        const url = urls[index];
        if (!url) continue;

        try {
          // 1. ApiFlashでフルページキャプチャ取得
          const apiUrl = `https://api.apiflash.com/v1/urltoimage?access_key=${API_FLASH_KEY}&url=${encodeURIComponent(url)}&width=${width}&format=webp&response_type=image&full_page=true&ttl=2592000`;

          const captureResponse = await fetch(apiUrl);
          if (!captureResponse.ok) {
            processLogs.push(`${url} はキャプチャの取得自体に失敗しました。`);
            continue;
          }

          const imageArrayBuffer = await captureResponse.arrayBuffer();
          let imageBuffer = Buffer.from(imageArrayBuffer);

          // 2. ファーストビューを切り出す
          const finalImageBuffer = await cropWebpImage(imageBuffer, width, height);

          // 3. AI画像診断（OCRおよび顔検出）
          let isNg;
          try {
            isNg = await checkImageForNgWords(finalImageBuffer);
          } catch (e) {
            return res.status(500).json({
              error: `テキスト解析(OCR)中にエラーが発生しました: ${e.message}`,
            });
          }

          const originalHost = new URL(url).hostname;
          const cleanHost = originalHost.replace(/^www\./, "");
          const imageName = `${cleanHost}.webp`;

          if (isNg !== false) {
            const reason = isNg.reason;
            const vertices = isNg.vertices;
            processLogs.push(`${url} は「${reason}」が検出されました。`);

            // 赤枠付き証拠画像を作成
            let ngImageBuffer = finalImageBuffer;
            if (vertices) {
              ngImageBuffer = await drawRedBox(finalImageBuffer, vertices);
            }

            finalResults.push({
              url: url,
              name: imageName,
              status: "ng",
              ng_reason: reason,
              image: `data:image/webp;base64,${finalImageBuffer.toString("base64")}`,
              ng_image: `data:image/webp;base64,${ngImageBuffer.toString("base64")}`,
            });
          } else {
            processLogs.push(`${url} は正常に取り込みが完了しました。`);
            finalResults.push({
              url: url,
              name: imageName,
              status: "ok",
              image: `data:image/webp;base64,${finalImageBuffer.toString("base64")}`,
            });
          }

          successfulCaptures++;
        } catch (e) {
          console.error(`Error processing ${url}:`, e);
          processLogs.push(`${url} はキャプチャの取得自体に失敗しました。`);
        }

        // APIレート制限回避
        if (urls.length > 1) {
          await new Promise((resolve) => setTimeout(resolve, 1000));
        }
      }

      if (successfulCaptures === 0) {
        return res.status(500).json({
          error: `有効な画像を1枚も取得できませんでした。\nログ:\n${processLogs.join("\n")}`,
        });
      }

      // 使用状況の更新
      try {
        await incrementUsageCount(successfulCaptures);
      } catch (e) {
        console.error("Usage update error:", e);
      }

      return res.json({
        success: true,
        logs: processLogs,
        results: finalResults,
      });
    });
  });
