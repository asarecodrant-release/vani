const express = require("express");
const axios = require("axios");
require("dotenv").config();

const app = express();

app.use(express.json({ limit: "20mb" }));

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
        model: process.env.MODEL,
        status: "online"
    });
});

app.post("/v1/chat/completions", async (req, res) => {

    try {

        const authHeader = req.headers.authorization;

        if (authHeader !== `Bearer ${process.env.API_KEY}`) {
            return res.status(401).json({
                error: "Invalid API key"
            });
        }

        const messages = req.body.messages || [];

        const prompt = messages
            .map(message => `${message.role}: ${message.content}`)
            .join("\n");

        const ollamaResponse = await axios.post(
            `${process.env.OLLAMA_URL}/api/generate`,
            {
                model: process.env.MODEL,
                prompt,
                stream: false
            },
            {
                timeout: 300000
            }
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

const PORT = process.env.PORT || 3000;

app.listen(PORT, () => {
    console.log(`AI Service running on port ${PORT}`);
});