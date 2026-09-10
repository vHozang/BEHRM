import axiosClient from './axiosClient';

/**
 * AI assistant service — talks to the backend POST /ai/ask endpoint.
 *
 * The backend takes the employee id from the auth token (request attribute
 * auth_employee_id), so we NEVER send it from the client. We only forward the
 * user's message plus a short slice of recent conversation history.
 *
 * Contract:
 *   ask(message, history) -> POST /ai/ask { message, history }
 *   resolves to response.data === { answer, configured, usage }
 *     - answer:     string  (assistant reply, or a friendly fallback)
 *     - configured: boolean (false when ANTHROPIC_API_KEY is missing)
 *     - usage:      { input_tokens, output_tokens } | null
 *
 * NOTE: the backend always returns HTTP 200 (even when unconfigured or on an
 * upstream error), so a rejected promise here means a transport/network failure.
 */
export const aiService = {
  async ask(message, history = []) {
    const response = await axiosClient.post('/ai/ask', {
      message,
      history,
    });
    return response.data;
  },
};

export default aiService;
