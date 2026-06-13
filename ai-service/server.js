const express = require("express");
const axios = require("axios");
require("dotenv").config();

const app = express();

app.use(express.json({ limit: "20mb" }));

// ======================
// HEALTH ROUTES
// ======================
app.get("/", (req, res) => {
    res.json({
        success: true,
        service: "AI Service",
        model: process.env.MODEL
    });
});

app.get("/health", (req, res) => {
    res.json({
        success: true,
        status: "online",
        model: process.env.MODEL
    });
});

// ======================
// AUTH MIDDLEWARE (simple)
// ======================
function checkAuth(req, res) {
    const authHeader = req.headers.authorization;
    return authHeader === `Bearer ${process.env.API_KEY}`;
}

// ======================
// CHAT COMPLETIONS
// ======================
app.post("/v1/chat/completions", async (req, res) => {
    try {
        if (!checkAuth(req, res)) {
            return res.status(401).json({ error: "Invalid API key" });
        }

        const messages = req.body.messages || [];

        const prompt = messages
            .map(m => `${m.role}: ${m.content}`)
            .join("\n");

        const ollamaResponse = await axios.post(
            `${process.env.OLLAMA_URL}/api/generate`,
            {
                model: process.env.MODEL,
                prompt,
                stream: false
            },
            { timeout: 300000 }
        );

        return res.json({
            id: `chatcmpl-${Date.now()}`,
            object: "chat.completion",
            created: Math.floor(Date.now() / 1000),
            model: process.env.MODEL,
            choices: [
                {
                    index: 0,
                    finish_reason: "stop",
                    message: {
                        role: "assistant",
                        content: ollamaResponse.data.response
                    }
                }
            ]
        });

    } catch (error) {
        console.error(error?.response?.data || error.message);

        return res.status(500).json({
            error: "AI request failed"
        });
    }
});

// ======================
// EMBEDDINGS (FIX FOR YOUR ERROR)
// ======================
app.post("/v1/embeddings", async (req, res) => {
    try {
        if (!checkAuth(req, res)) {
            return res.status(401).json({ error: "Invalid API key" });
        }

        const input = req.body.input;

        if (!input) {
            return res.status(400).json({
                error: "Missing input for embeddings"
            });
        }

        const ollamaResponse = await axios.post(
            `${process.env.OLLAMA_URL}/api/embeddings`,
            {
                model: "nomic-embed-text",
                prompt: input
            },
            { timeout: 300000 }
        );

        return res.json({
            object: "list",
            data: [
                {
                    object: "embedding",
                    embedding: ollamaResponse.data.embedding,
                    index: 0
                }
            ],
            model: "nomic-embed-text"
        });

    } catch (error) {
        console.error(error?.response?.data || error.message);

        return res.status(500).json({
            error: "Embedding request failed"
        });
    }
});

// ======================
// START SERVER
// ======================
const PORT = process.env.PORT || 3000;

app.listen(PORT, () => {
    console.log(`AI Service running on port ${PORT}`);
});