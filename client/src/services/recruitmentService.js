import axiosClient from './axiosClient';

const stageToBackend = {
  applied: 'PENDING',
  shortlisted: 'SCREENING',
  interviewing: 'INTERVIEWING',
  offered: 'OFFERED',
  rejected: 'REJECTED',
  hired: 'HIRED'
};

const backendToStage = {
  PENDING: 'applied',
  SCREENING: 'shortlisted',
  INTERVIEWING: 'interviewing',
  OFFERED: 'offered',
  REJECTED: 'rejected',
  HIRED: 'hired'
};

const normalizeCandidate = (candidate) => {
  if (!candidate || typeof candidate !== 'object') return candidate;
  const status = candidate.status || backendToStage[candidate.application_status] || 'applied';
  const cv = candidate.cv && typeof candidate.cv === 'object' ? candidate.cv : null;
  return {
    ...candidate,
    status,
    application_status: candidate.application_status || stageToBackend[status] || status,
    phone: candidate.phone || candidate.phone_number || '',
    phone_number: candidate.phone_number || candidate.phone || '',
    recruitment_position_title:
      candidate.recruitment_position_title ||
      candidate.position?.position_name ||
      candidate.position?.position_title ||
      candidate.position?.title ||
      candidate.job_title_name ||
      candidate.position_title ||
      candidate.position_name ||
      '',
    cv,
    cv_path: candidate.cv_path || cv?.storage_path || candidate.storage_path || '',
    cv_original_filename: candidate.cv_original_filename || cv?.original_filename || ''
  };
};

const candidatePayload = (data) => {
  const payload = { ...data };
  if (payload.status && !payload.application_status) {
    payload.application_status = stageToBackend[payload.status] || payload.status;
  }
  if (payload.phone && !payload.phone_number) {
    payload.phone_number = payload.phone;
  }
  delete payload.status;
  delete payload.phone;
  return payload;
};

export const recruitmentService = {
  // --- Public careers landing ---
  getPublicPosts: async () => {
    const response = await axiosClient.get('/public/recruitment-posts');
    return response.data;
  },
  getPublicPost: async (slug) => {
    const response = await axiosClient.get(`/public/recruitment-posts/${encodeURIComponent(slug)}`);
    return response.data;
  },
  submitPublicApplication: async (formData) => {
    const response = await axiosClient.post('/public/recruitment/applications', formData);
    return response.data;
  },

  // --- Recruitment post publishing ---
  getRecruitmentPosts: async (params) => {
    const response = await axiosClient.get('/recruitment-posts', { params });
    return response.data;
  },
  getRecruitmentPost: async (id) => {
    const response = await axiosClient.get(`/recruitment-posts/${id}`);
    return response.data;
  },
  createRecruitmentPost: async (data) => {
    const response = await axiosClient.post('/recruitment-posts', data);
    return response.data;
  },
  updateRecruitmentPost: async (id, data) => {
    const response = await axiosClient.put(`/recruitment-posts/${id}`, data);
    return response.data;
  },
  deleteRecruitmentPost: async (id) => {
    const response = await axiosClient.delete(`/recruitment-posts/${id}`);
    return response.data;
  },

  // --- Positions ---
  getAllPositions: async (params) => {
    const response = await axiosClient.get('/recruitment-positions', { params });
    return response.data;
  },
  createPosition: async (data) => {
    const response = await axiosClient.post('/recruitment-positions', data);
    return response.data;
  },
  updatePosition: async (id, data) => {
    const response = await axiosClient.put(`/recruitment-positions/${id}`, data);
    return response.data;
  },
  deletePosition: async (id) => {
    const response = await axiosClient.delete(`/recruitment-positions/${id}`);
    return response.data;
  },

  // --- Candidates ---
  getAllCandidates: async (params) => {
    const mappedParams = { ...params };
    if (mappedParams.status && !mappedParams.application_status) {
      mappedParams.application_status = stageToBackend[mappedParams.status] || mappedParams.status;
      delete mappedParams.status;
    }
    const response = await axiosClient.get('/recruitment-candidates', { params: mappedParams });
    return Array.isArray(response.data) ? response.data.map(normalizeCandidate) : normalizeCandidate(response.data);
  },
  getCandidateById: async (id) => {
    const response = await axiosClient.get(`/recruitment-candidates/${id}`);
    return normalizeCandidate(response.data);
  },
  createCandidate: async (data) => {
    const response = await axiosClient.post('/recruitment-candidates', candidatePayload(data));
    return normalizeCandidate(response.data);
  },
  updateCandidate: async (id, data) => {
    const response = await axiosClient.patch(`/recruitment-candidates/${id}`, candidatePayload(data));
    return normalizeCandidate(response.data);
  },
  deleteCandidate: async (id) => {
    const response = await axiosClient.delete(`/recruitment-candidates/${id}`);
    return response.data;
  },
  uploadCv: async (candidateId, file) => {
    const formData = new FormData();
    formData.append('file', file);
    const response = await axiosClient.post(`/recruitment-candidates/${candidateId}/cv`, formData, {
      headers: {
        'Content-Type': 'multipart/form-data'
      }
    });
    return response.data;
  },
  downloadCv: async (candidateId) => {
    const response = await axiosClient.get(`/recruitment-candidates/${candidateId}/cv`, {
      responseType: 'blob'
    });
    return response.data;
  },
  retryAiScore: async (candidateId) => {
    const response = await axiosClient.post(`/recruitment-candidates/${candidateId}/ai-score/retry`);
    return response.data;
  },
  advance: async (id) => {
    const response = await axiosClient.post(`/recruitment-candidates/${id}/advance`);
    return normalizeCandidate(response.data);
  },
  hire: async (id, payload = {}) => {
    const response = await axiosClient.post(`/recruitment-candidates/${id}/hire`, payload);
    return normalizeCandidate(response.data);
  },
  reject: async (id, payload = {}) => {
    const response = await axiosClient.post(`/recruitment-candidates/${id}/reject`, payload);
    return normalizeCandidate(response.data);
  },
  managerReview: async (id, payload) => {
    const response = await axiosClient.patch(`/recruitment-candidates/${id}/manager-review`, payload);
    return normalizeCandidate(response.data);
  },
  getAiFeedbackStats: async () => {
    const response = await axiosClient.get('/recruitment-ai/feedback-stats');
    return response.data;
  },

  // --- Interviews ---
  getAllInterviews: async (params) => {
    const response = await axiosClient.get('/interviews', { params });
    return response.data;
  },
  createInterview: async (data) => {
    const response = await axiosClient.post('/interviews', data);
    return response.data;
  },
  updateInterview: async (id, data) => {
    const response = await axiosClient.put(`/interviews/${id}`, data);
    return response.data;
  },
  deleteInterview: async (id) => {
    const response = await axiosClient.delete(`/interviews/${id}`);
    return response.data;
  },
  interviewManagerReview: async (id, payload) => {
    const response = await axiosClient.patch(`/interviews/${id}/manager-review`, payload);
    return response.data;
  }
};
