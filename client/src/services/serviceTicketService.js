import axiosClient from './axiosClient';

export const serviceTicketService = {
  getAllTickets: async (params) => {
    const response = await axiosClient.get('/service-tickets', { params });
    return response.data;
  },
  getById: async (id) => {
    const response = await axiosClient.get(`/service-tickets/${id}`);
    return response.data;
  },
  createTicket: async (data) => {
    const response = await axiosClient.post('/service-tickets', data);
    return response.data;
  },
  updateTicket: async (id, data) => {
    const response = await axiosClient.put(`/service-tickets/${id}`, data);
    return response.data;
  },
  deleteTicket: async (id) => {
    const response = await axiosClient.delete(`/service-tickets/${id}`);
    return response.data;
  }
};
