<?php
$usageFile = 'usage.json';
$limit = 100;
$currentMonth = date('Y-m');
$count = 0;

if (file_exists($usageFile)) {
    $data = json_decode(file_get_contents($usageFile), true);
    if (isset($data['month']) && $data['month'] === $currentMonth) {
        $count = $data['count'] ?? 0;
    }
}
$usageMessage = "今月の作成枚数 (目安): {$count} / {$limit} 枚";
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>一括キャプ太郎 | WEBサイトキャプチャ生成（医療広告ガイドライン対応）</title>
    <style>
        body {
            font-family: sans-serif;
            padding: 20px;
            max-width: 800px;
            margin: 0 auto;
        }

        .form-group {
            margin-bottom: 25px;
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid #eaeaea;
        }

        .form-group label {
            display: block;
            margin-bottom: 10px;
            font-weight: bold;
            color: #495057;
        }

        input[type="text"],
        input[type="number"],
        textarea {
            padding: 12px 15px;
            width: 100%;
            box-sizing: border-box;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 1rem;
            font-family: inherit;
            transition: border-color 0.3s, box-shadow 0.3s;
            background-color: #fcfcfc;
        }

        input[type="text"]:focus,
        input[type="number"]:focus,
        textarea:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 0 4px rgba(0, 123, 255, 0.15);
            background-color: #fff;
        }

        button {
            padding: 10px 20px;
            background-color: #007bff;
            color: white;
            border: none;
            cursor: pointer;
            border-radius: 4px;
        }

        button:hover {
            background-color: #0056b3;
        }

        button:disabled {
            background-color: #ccc;
            cursor: not-allowed;
        }

        #status {
            margin-top: 15px;
            color: #666;
            font-weight: bold;
        }

        #resultArea {
            margin-top: 30px;
            display: none;
        }

        #resultImage {
            max-width: 100%;
            border: 1px solid #ddd;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .download-btn {
            display: inline-block;
            margin-top: 10px;
            background-color: #28a745;
            text-decoration: none;
            color: white;
            padding: 10px 20px;
            border-radius: 4px;
        }

        #captureBtn {
            background: linear-gradient(135deg, #007bff, #0056b3);
            padding: 15px 40px;
            font-size: 1.3rem;
            font-weight: bold;
            border-radius: 50px;
            box-shadow: 0 4px 15px rgba(0, 123, 255, 0.3);
            transition: all 0.3s ease;
        }

        #captureBtn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 123, 255, 0.4);
            background: linear-gradient(135deg, #008cff, #0062cc);
        }

        #captureBtn:disabled {
            background: #cccccc;
            box-shadow: none;
            transform: none;
        }

        #usageInfo {
            padding: 10px;
            background-color: #f3f3f3;
            border-radius: 4px;
            margin-bottom: 20px;
            font-weight: bold;
            color: #333;
        }
    </style>
</head>

<body>

    <div style="display: flex; align-items: center; margin-bottom: 20px; background: #fff; padding: 15px 20px; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border: 1px solid #eaeaea;">
        <img src="logo.png" alt="一括キャプ太郎" style="width: 70px; height: 70px; border-radius: 50%; border: 3px solid #007bff; margin-right: 20px; box-shadow: 0 4px 8px rgba(0,123,255,0.2);">
        <h2 style="margin: 0; display: flex; flex-direction: column;">
            <span style="font-size: 1.5em; color: #007bff; letter-spacing: 1px;">一括キャプ太郎</span>
            <span style="font-size: 0.55em; color: #6c757d; margin-top: 5px; font-weight: normal;">WEBサイトキャプチャ生成ツール（医療広告ガイドライン対応）</span>
        </h2>
    </div>

    <div id="usageInfo">
        <?php echo $usageMessage; ?>
    </div>

    <div
        style="background-color: transparent; border: 1px solid #ced4da; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
        <h3 style="margin-top: 0; margin-bottom: 10px; font-size: 1rem; color: #495057;">📝 ツールの使い方</h3>
        <ol style="margin-bottom: 0; padding-left: 20px; line-height: 1.6; color: #333; font-size: 0.9em;">
            <li>ファーストビューのキャプチャ画像を生成したいURLを入力します（改行して複数入力可能。最大5件）。</li>
            <li><strong>「✨ キャプチャを生成する」</strong>ボタンをクリックします。</li>
            <li>AIが画像を作成し、<strong>NGワードと人物写真</strong>が含まれていないかを自動判定します。</li>
            <li>NG判定が出た画像は、「本当にNGワードか？」「人物写真に著名人が使われていないか？」をご自身で確認した上で、<strong>「問題無し」</strong>として許可するか、<strong>「別のURLを指定して再取得」</strong>するかを個別に選択してください。
            </li>
            <li>すべての画像が「問題無し」状態になると、一括で<strong>ZIPダウンロード</strong>が可能になります。</li>
        </ol>
    </div>



    <div id="ngWordsInfo"
        style="padding: 10px; background-color: #ffeaea; border: 1px solid #ffcccc; border-radius: 4px; margin-bottom: 20px; font-size: 0.9em; display: none;">
        <strong>NGワード一覧</strong>
        <ul id="ngWordsList"
            style="margin-top: 10px; margin-bottom: 0; padding-left: 0; list-style-position: inside; display: grid; grid-template-columns: repeat(4, 1fr); gap: 4px 10px;">
        </ul>
    </div>

    <div class="form-group">
        <label>対象のURL <span
                style="font-weight: normal; color: #777; font-size: 0.9em;">（最大5件まで、改行で区切ってください）</span></label>
        <textarea id="urlInput" placeholder="https://example.com&#10;https://google.com" rows="5"></textarea>
    </div>

    <div style="display: flex; gap: 20px;" class="form-group">
        <div style="flex: 1;">
            <label>取得する幅 (px)</label>
            <input type="number" id="widthInput" value="1280">
        </div>
        <div style="flex: 1;">
            <label>取得する高さ (px)</label>
            <input type="number" id="heightInput" value="650">
        </div>
    </div>

    <div style="text-align: center; margin-top: 30px; margin-bottom: 20px;">
        <button id="captureBtn" onclick="takeScreenshot()">✨ キャプチャを生成する</button>
    </div>
    <div id="status"></div>

    <div id="progressContainer" style="display: none; margin-top: 15px;">
        <div style="display: flex; justify-content: space-between; margin-bottom: 5px; font-size: 0.9em; color: #555;">
            <span id="progressLabel">処理中...</span>
            <span id="progressText">0%</span>
        </div>
        <div style="width: 100%; background-color: #e9ecef; border-radius: 4px; overflow: hidden; height: 10px;">
            <div id="progressBar"
                style="width: 0%; height: 100%; background-color: #007bff; transition: width 0.3s ease;"></div>
        </div>
    </div>

    <div id="processLogs"
        style="margin-top: 15px; padding: 10px; background-color: #f8f9fa; border-left: 4px solid #007bff; display: none; white-space: pre-wrap; font-size: 0.9em; line-height: 1.5;">
    </div>

    <div id="resultArea"
        style="display: none; padding: 20px; border-radius: 8px; background-color: #f8f9fa; border: 1px solid #dee2e6;">
        <h3 id="resultTitle" style="margin-top: 0; margin-bottom: 20px;">判定結果</h3>

        <div id="imagesContainer">
            <!-- プレビュー画像がここに差し込まれる -->
        </div>

        <div id="actionButtons" style="display: none; gap: 15px; margin-top: 30px;">
            <button id="downloadAllBtn" onclick="downloadAll()"
                style="flex: 1; background-color: #28a745; color: white; border: none; padding: 15px; border-radius: 5px; cursor: pointer; font-size: 1.1em; font-weight: bold;">すべての画像をダウンロードする</button>
        </div>
    </div>

    <script>
        let globalState = [];

        function markAsOk(index) {
            globalState[index].status = 'ok';
            renderResults();
        }

        async function retryUrl(index) {
            const inputEl = document.getElementById('retryInput_' + index);
            const newUrl = inputEl.value.trim();
            if (!newUrl) {
                alert('URLを入力してください');
                return;
            }

            const btn = document.getElementById('retryBtn_' + index);
            btn.disabled = true;
            btn.textContent = '取得中...';

            const width = document.getElementById('widthInput').value;
            const height = document.getElementById('heightInput').value;

            try {
                const response = await fetch('capture.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ urls: [newUrl], width, height })
                });

                if (!response.ok) throw new Error('通信エラー');

                const text = await response.text();
                const lines = text.split('\n').filter(l => l.trim());
                if (lines.length === 0) throw new Error('空のレスポンス');

                const lastLine = lines[lines.length - 1]; // ストリーミングの最終行がJSON結果
                const data = JSON.parse(lastLine);

                if (data.success && data.results && data.results.length > 0) {
                    globalState[index] = data.results[0]; // 状態を更新
                    renderResults();
                } else {
                    alert('画面の取得に失敗しました');
                    btn.disabled = false;
                    btn.textContent = 'この枠だけ再取得';
                }
            } catch (e) {
                alert('エラーが発生しました: ' + e.message);
                btn.disabled = false;
                btn.textContent = 'この枠だけ再取得';
            }
        }

        function renderResults() {
            const container = document.getElementById('imagesContainer');
            container.innerHTML = '';

            let allOk = true;

            globalState.forEach((item, index) => {
                const box = document.createElement('div');
                box.style.marginBottom = '30px';
                box.style.padding = '15px';
                box.style.border = '1px solid #ccc';
                box.style.borderRadius = '5px';
                box.style.backgroundColor = '#fff';

                let infoHtml = '';
                if (item.intro && item.points) {
                    const pointsHtml = item.points.map(p => `<li>${p}</li>`).join('');
                    infoHtml = `
                        <div style="margin-top: 20px; padding: 20px; background: #f1f8ff; border: 1px solid #cce5ff; border-radius: 8px;">
                            <h5 style="margin-top: 0; margin-bottom: 10px; color: #004085; font-size: 1.05em;">📝 店舗紹介文</h5>
                            <p style="font-size: 0.95em; color: #333; line-height: 1.6; margin-bottom: 20px; white-space: pre-wrap;">${item.intro}</p>
                            <h5 style="margin-top: 0; margin-bottom: 10px; color: #004085; font-size: 1.05em;">✨ おすすめポイント</h5>
                            <ul style="margin: 0; padding-left: 20px; font-size: 0.95em; color: #333; line-height: 1.6;">
                                ${pointsHtml}
                            </ul>
                        </div>
                    `;
                }

                if (item.status === 'ok') {
                    box.style.borderColor = '#28a745';
                    box.innerHTML = `
                        <h4 style="color: #28a745; margin-top: 0;">✅ 問題無し: <a href="${item.url}" target="_blank" style="color: #28a745; text-decoration: underline;">${item.url}</a></h4>
                        <img src="${item.image}" style="max-width: 100%; border: 1px solid #eee;">
                        ${infoHtml}
                    `;
                } else {
                    allOk = false;
                    box.style.borderColor = '#dc3545';
                    box.innerHTML = `
                        <h4 style="color: #dc3545; margin-top: 0;">⚠️ NG判定: <a href="${item.url}" target="_blank" style="color: #dc3545; text-decoration: underline;">${item.url}</a></h4>
                        <p style="color: #dc3545; font-weight: bold;">理由: ${item.ng_reason}</p>
                        <img src="${item.ng_image}" style="max-width: 100%; border: 3px solid #dc3545; margin-bottom: 15px;">
                        ${infoHtml}
                        <div style="background: #f8f9fa; padding: 15px; border-radius: 5px; margin-top: 15px;">
                            <p style="margin-top: 0; font-weight: bold;">この画像に対するアクションを選択してください：</p>
                            <button onclick="markAsOk(${index})" style="background-color: #ffc107; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; font-weight: bold; margin-bottom: 15px;">特例として「問題無し」とする</button>
                            
                            <div style="display: flex; gap: 10px;">
                                <input type="text" id="retryInput_${index}" placeholder="別のURLを入力して再取得..." style="flex: 1; padding: 8px;">
                                <button id="retryBtn_${index}" onclick="retryUrl(${index})" style="background-color: #6c757d; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; white-space: nowrap;">この枠だけ再取得</button>
                            </div>
                        </div>
                    `;
                }
                container.appendChild(box);
            });

            const actionButtons = document.getElementById('actionButtons');
            if (allOk && globalState.length > 0) {
                actionButtons.style.display = 'flex';
                // 最初に入力されたURL一覧の数と等しい場合だけZIPボタンの文言を変えるなどできるが、一律でこれで完結させる
            } else {
                actionButtons.style.display = 'none';
            }
        }

        async function downloadAll() {
            const btn = document.getElementById('downloadAllBtn');
            btn.disabled = true;
            btn.textContent = 'ダウンロード準備中...';

            // 常にZIP化をバックエンドへ依頼する（テキストデータを含めるため）
            const images = globalState.map(s => ({ name: s.name, data: s.image }));
            let textsContent = '';
            globalState.forEach(s => {
                if (s.intro && s.points) {
                    textsContent += `【URL】${s.url}\n`;
                    textsContent += `【店舗紹介文】\n${s.intro}\n\n`;
                    textsContent += `【おすすめポイント】\n`;
                    s.points.forEach((p, i) => textsContent += ` ${i+1}. ${p}\n`);
                    textsContent += `----------------------------------------\n\n`;
                }
            });

            try {
                const response = await fetch('capture.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'zip', images: images, texts_content: textsContent })
                });
                const result = await response.json();
                if (result.success && result.zip_base64) {
                    const binary = atob(result.zip_base64);
                    const array = new Uint8Array(binary.length);
                    for (let i = 0; i < binary.length; i++) array[i] = binary.charCodeAt(i);
                    const blob = new Blob([array], { type: 'application/zip' });
                    const downloadUrl = window.URL.createObjectURL(blob);

                    const a = document.createElement('a');
                    a.href = downloadUrl;
                    a.download = 'screenshots.zip';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                } else {
                    alert('ZIPファイルの作成に失敗しました');
                }
            } catch (e) {
                alert('エラーが発生しました: ' + e.message);
            } finally {
                btn.disabled = false;
                btn.textContent = 'すべての画像をダウンロードする';
            }
        }
        function resetForm() {
            document.getElementById('urlInput').value = '';
            document.getElementById('urlInput').focus();
            document.getElementById('resultArea').style.display = 'none';
            document.getElementById('processLogs').style.display = 'none';
            document.getElementById('status').textContent = '';
            document.getElementById('progressContainer').style.display = 'none';
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        // NGワードの読み込み
        document.addEventListener('DOMContentLoaded', async () => {
            try {
                const response = await fetch('capture.php', { method: 'GET' });
                if (response.ok) {
                    const data = await response.json();
                    if (data.ngWords && data.ngWords.length > 0) {
                        const listEl = document.getElementById('ngWordsList');
                        listEl.innerHTML = ''; // クリア
                        data.ngWords.forEach(word => {
                            const li = document.createElement('li');
                            li.textContent = word;
                            listEl.appendChild(li);
                        });
                        document.getElementById('ngWordsInfo').style.display = 'block';
                    }
                }
            } catch (error) {
                console.error('NGワードの読み込みに失敗しました', error);
            }
        });

        async function takeScreenshot() {
            const urlsValue = document.getElementById('urlInput').value;
            const width = document.getElementById('widthInput').value;
            const height = document.getElementById('heightInput').value;

            const btn = document.getElementById('captureBtn');
            const status = document.getElementById('status');
            const progressContainer = document.getElementById('progressContainer');
            const progressBar = document.getElementById('progressBar');
            const progressText = document.getElementById('progressText');
            const resultArea = document.getElementById('resultArea');
            const downloadLink = document.getElementById('downloadLink');
            const usageInfo = document.getElementById('usageInfo');

            const processLogsDiv = document.getElementById('processLogs');

            const urls = urlsValue.split('\n').map(u => u.trim()).filter(u => u);

            if (urls.length === 0) {
                alert('URLを入力してください');
                return;
            }
            if (urls.length > 5) {
                alert('URLは5件までしか一度に処理できません');
                return;
            }

            // UIを読み込み中に変更
            btn.disabled = true;
            const statusMessage = urls.length > 1 ? `⏳ ${urls.length}件のキャプチャを生成中...（数十秒〜数分かかる場合があります）` : '⏳ キャプチャを生成中...（数秒〜十数秒かかります）';
            status.textContent = statusMessage;
            resultArea.style.display = 'none';
            processLogsDiv.style.display = 'none';
            processLogsDiv.textContent = '';

            // プログレスバーの初期化と表示
            progressContainer.style.display = 'block';
            progressBar.style.width = '0%';
            progressText.textContent = '0%';
            progressBar.style.backgroundColor = '#007bff';

            try {
                const response = await fetch('capture.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ urls: urls, width, height })
                });

                if (!response.ok) {
                    const errorResult = await response.json().catch(() => ({}));
                    throw new Error(errorResult.error || `サーバーエラー: ${response.statusText}`);
                }

                // 成功した場合、使用状況ヘッダーを更新
                const newCount = response.headers.get('X-Usage-Count');
                if (newCount !== null) {
                    usageInfo.textContent = `今月の作成枚数 (目安): ${newCount} / 100 枚`;
                }

                const contentType = response.headers.get('content-type');
                let headerLogsText = '';
                const base64Logs = response.headers.get('X-Process-Logs');
                if (base64Logs) {
                    try {
                        const decodedStr = atob(base64Logs);
                        const utf8Arr = new Uint8Array(decodedStr.length);
                        for (let i = 0; i < decodedStr.length; i++) { utf8Arr[i] = decodedStr.charCodeAt(i); }
                        headerLogsText = new TextDecoder().decode(utf8Arr);
                    } catch (e) { console.error('ログのデコードエラー', e); }
                }

                const displayLogs = (text) => {
                    if (text) {
                        processLogsDiv.textContent = text;
                        processLogsDiv.style.display = 'block';
                    }
                };

                let result = null;

                // レスポンスは常にJSONで返ってくる
                if (contentType && contentType.includes('application/json')) {
                    // ストリーミングレスポンスを読み取る
                    const reader = response.body.getReader();
                    const decoder = new TextDecoder();
                    let buffer = '';

                    while (true) {
                        const { done, value } = await reader.read();
                        if (done) break;
                        buffer += decoder.decode(value, { stream: true });
                        const lines = buffer.split('\n');
                        buffer = lines.pop(); // 最後の不完全な行を残す

                        for (const line of lines) {
                            if (!line.trim()) continue;
                            try {
                                const data = JSON.parse(line);
                                if (data.progress) {
                                    progressBar.style.width = data.percent + '%';
                                    progressText.textContent = data.percent + '%';
                                    if (data.message) {
                                        document.getElementById('progressLabel').textContent = data.message;
                                    }
                                } else {
                                    // 最終結果データ
                                    result = data;
                                }
                            } catch (e) {
                                console.error("JSON parse error on chunk:", line, e);
                            }
                        }
                    }

                    if (!result) throw new Error("正しい結果データを受信できませんでした");
                    if (!result.success) {
                        throw new Error(result.error || '不明なエラーが発生しました');
                    }

                    if (result.results) {
                        globalState = result.results;
                        renderResults();
                    }
                    resultArea.style.display = 'block';

                    if (result.logs && result.logs.length > 0) {
                        displayLogs(result.logs.join('\n'));
                    } else if (headerLogsText) {
                        displayLogs(headerLogsText);
                    }
                } else {
                    throw new Error('予期しないレスポンス形式です: ' + contentType);
                }
                status.textContent = '';
            } catch (error) {
                status.textContent = '❌ エラー: ' + error.message;
                progressBar.style.backgroundColor = '#dc3545'; // エラー時は赤色に
            } finally {
                btn.disabled = false;
                document.getElementById('progressLabel').textContent = '完了';
                if (!status.textContent.includes('エラー')) {
                    progressBar.style.width = '100%';
                    progressText.textContent = '100%';
                    setTimeout(() => {
                        progressContainer.style.display = 'none';
                    }, 1500);
                }
            }
        }
    </script>
    <footer style="text-align: center; margin-top: 50px; padding-top: 20px; border-top: 1px solid #eaeaea; color: #999; font-size: 0.85em;">
        Powered by リードクリエーション株式会社
    </footer>
</body>

</html>